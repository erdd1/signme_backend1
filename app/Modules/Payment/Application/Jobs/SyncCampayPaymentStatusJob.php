<?php

namespace App\Modules\Payment\Application\Jobs;

use App\Models\PaymentTransaction;
use App\Modules\Payment\Application\Services\PaymentStatusCache;
use App\Modules\Payment\Infrastructure\ExternalServices\CampayGateway;
use App\Modules\Signature\Application\Services\SignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie le statut Campay en arrière-plan (file Redis payments).
 */
class SyncCampayPaymentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 45;

    public function __construct(
        public int $transactionId,
        public string $campayReference,
        public int $attempt = 1,
    ) {
        $this->onConnection(config('payment.connection', 'redis'));
        $this->onQueue(config('payment.queue', 'payments'));
    }

    public function handle(
        CampayGateway $campayGateway,
        SignatureService $signatureService,
    ): void {
        $transaction = PaymentTransaction::find($this->transactionId);

        if (!$transaction) {
            Log::warning('SyncCampayPaymentStatusJob: transaction introuvable', [
                'transactionId' => $this->transactionId,
            ]);
            return;
        }

        if (in_array($transaction->status, ['completed', 'failed'], true)) {
            PaymentStatusCache::putFromTransaction($transaction);
            return;
        }

        $campayData = $campayGateway->checkPaymentStatus($this->campayReference);

        if (!$campayData) {
            Log::info('SyncCampayPaymentStatusJob: statut Campay indisponible', [
                'transactionId' => $this->transactionId,
                'attempt'       => $this->attempt,
            ]);
            return;
        }

        $campayStatus = $campayData['status'] ?? null;

        if (in_array($campayStatus, ['SUCCESSFUL', 'FAILED'], true)) {
            $signatureService->processCampayNotification(
                array_merge($campayData, [
                    'reference' => $this->campayReference,
                    'status'    => $campayStatus,
                ])
            );

            $transaction->refresh();
            PaymentStatusCache::putFromTransaction($transaction);

            Log::info('SyncCampayPaymentStatusJob: transaction synchronisée', [
                'transactionId' => $this->transactionId,
                'status'        => $transaction->status,
            ]);

            return;
        }

        // Toujours en attente côté Campay
        PaymentStatusCache::putFromTransaction($transaction->fresh());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncCampayPaymentStatusJob échoué', [
            'transactionId' => $this->transactionId,
            'attempt'       => $this->attempt,
            'error'         => $exception->getMessage(),
        ]);
    }
}
