<?php

namespace App\Modules\Payment\Application\Jobs;

use App\Models\PaymentEvent;
use App\Models\PaymentTransaction;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Application\Services\PaymentStatusCache;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Signature\Application\Services\SignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie le statut NotchPay en arrière-plan (file Redis payments).
 */
class SyncPaymentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 45;

    public function __construct(
        public int $transactionId,
        public string $paymentReference,
        public int $attempt = 1,
    ) {
        $this->onConnection(config('payment.connection', 'redis'));
        $this->onQueue(config('payment.queue', 'payments'));
    }

    public function handle(
        NotchPayGateway $notchPayGateway,
        SignatureService $signatureService,
    ): void {
        $transaction = PaymentTransaction::find($this->transactionId);

        if (!$transaction) {
            Log::warning('SyncPaymentStatusJob: transaction introuvable', [
                'transactionId' => $this->transactionId,
            ]);
            return;
        }

        if (in_array($transaction->status, ['completed', 'failed'], true)) {
            PaymentStatusCache::putFromTransaction($transaction);
            return;
        }

        $paymentData = $notchPayGateway->verifyPayment($this->paymentReference);

        if (!$paymentData) {
            Log::info('SyncPaymentStatusJob: statut NotchPay indisponible', [
                'transactionId' => $this->transactionId,
                'attempt'       => $this->attempt,
            ]);
            return;
        }

        $rawStatus = $paymentData['status'] ?? '';
        $internalStatus = $notchPayGateway->mapStatus($rawStatus);

        // Trace technique : chaque ping, y compris ceux qui restent pending.
        PaymentEventRecorder::recordSyncLog($this->transactionId, null, $internalStatus, $paymentData);

        if (in_array($internalStatus, ['completed', 'failed'], true)) {
            $signatureService->processPaymentNotification(
                array_merge($paymentData, [
                    'reference' => $this->paymentReference,
                    'status'    => $rawStatus,
                ])
            );

            $transaction->refresh();
            PaymentStatusCache::putFromTransaction($transaction);

            if ($transaction->status !== 'pending') {
                PaymentEventRecorder::recordEvent(
                    $this->transactionId,
                    null,
                    $transaction->status === 'completed' ? PaymentEvent::TYPE_CONFIRMED : PaymentEvent::TYPE_FAILED,
                    $transaction->status,
                    $paymentData,
                );
            }

            Log::info('SyncPaymentStatusJob: transaction synchronisée', [
                'transactionId' => $this->transactionId,
                'status'        => $transaction->status,
            ]);

            return;
        }

        // Toujours en attente côté NotchPay
        PaymentStatusCache::putFromTransaction($transaction->fresh());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncPaymentStatusJob échoué', [
            'transactionId' => $this->transactionId,
            'attempt'       => $this->attempt,
            'error'         => $exception->getMessage(),
        ]);
    }
}
