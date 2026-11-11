<?php

namespace App\Modules\Evenement\Application\Jobs;

use App\Models\Contribution;
use App\Models\PaymentEvent;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Application\Services\PaymentGatewayRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie le statut d'une contribution auprès de son fournisseur (NotchPay
 * pour le Mobile Money, Flutterwave pour la carte) en arrière-plan (file Redis
 * payments). Miroir de
 * App\Modules\Payment\Application\Jobs\SyncPaymentStatusJob — réutilise les
 * mêmes passerelles, ne duplique pas les appels API. Sert aussi de suite au
 * webhook Flutterwave : le webhook déclenche, cette vérification fait foi.
 */
class SyncEvenementContributionStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 45;

    public function __construct(
        public int $contributionId,
        public string $paymentReference,
        public int $attempt = 1,
    ) {
        $this->onConnection(config('payment.connection', 'redis'));
        $this->onQueue(config('payment.queue', 'payments'));
    }

    public function handle(
        PaymentGatewayRegistry $gateways,
        ContributionService $contributionService,
    ): void {
        $contribution = Contribution::find($this->contributionId);

        if (!$contribution) {
            Log::warning('SyncEvenementContributionStatusJob: contribution introuvable', [
                'contributionId' => $this->contributionId,
            ]);
            return;
        }

        if (in_array($contribution->statut, [Contribution::STATUT_COMPLETED, Contribution::STATUT_FAILED], true)) {
            return;
        }

        $gateway = $gateways->forContribution($contribution);
        $paymentData = $gateway->verifyPayment($this->paymentReference);

        if (!$paymentData) {
            Log::info('SyncEvenementContributionStatusJob: statut du paiement indisponible', [
                'contributionId' => $this->contributionId,
                'attempt'        => $this->attempt,
            ]);
            return;
        }

        $rawStatus = $paymentData['status'] ?? '';
        $internalStatus = $gateway->mapStatus($rawStatus);

        // Trace technique : chaque ping, y compris ceux qui restent pending.
        PaymentEventRecorder::recordSyncLog(null, $this->contributionId, $internalStatus, $paymentData);

        if (in_array($internalStatus, ['completed', 'failed'], true)) {
            $contributionService->processPaymentNotification(
                array_merge($paymentData, [
                    'reference' => $this->paymentReference,
                    'status'    => $rawStatus,
                ])
            );

            $contribution->refresh();
            if ($contribution->statut !== Contribution::STATUT_PENDING) {
                PaymentEventRecorder::recordEvent(
                    null,
                    $this->contributionId,
                    $contribution->statut === Contribution::STATUT_COMPLETED ? PaymentEvent::TYPE_CONFIRMED : PaymentEvent::TYPE_FAILED,
                    $contribution->statut,
                    $paymentData,
                );
            }

            Log::info('SyncEvenementContributionStatusJob: contribution synchronisée', [
                'contributionId' => $this->contributionId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncEvenementContributionStatusJob échoué', [
            'contributionId' => $this->contributionId,
            'attempt'        => $this->attempt,
            'error'          => $exception->getMessage(),
        ]);
    }
}
