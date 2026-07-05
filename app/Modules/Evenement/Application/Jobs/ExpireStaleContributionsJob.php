<?php

namespace App\Modules\Evenement\Application\Jobs;

use App\Models\Contribution;
use App\Models\PaymentEvent;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job planifié — Expire les contributions événement restées "pending" bien
 * au-delà du délai de polling normal (ex. worker de queue tombé en panne,
 * webhook jamais reçu). Miroir de
 * App\Modules\Payment\Application\Jobs\ExpireStalePaymentTransactionsJob —
 * réutilise le même NotchPayGateway, ne duplique pas l'appel API.
 *
 * Garde-fou : avant d'expirer quoi que ce soit, une dernière vérification
 * est faite directement auprès de NotchPay. Si NotchPay confirme un paiement
 * complete, la contribution est créditée normalement via
 * {@see ContributionService::processPaymentNotification()} (déjà idempotent :
 * ignore silencieusement les contributions déjà completed/failed), jamais
 * perdue. Seules celles sans confirmation NotchPay après le délai sont
 * marquées "failed".
 *
 * À exécuter via scheduler : Schedule::job(...)->everyFiveMinutes();
 */
class ExpireStaleContributionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        NotchPayGateway $notchPayGateway,
        ContributionService $contributionService,
    ): void {
        $minutes = (int) config('payment.pending_expiry_minutes', 15);

        $staleContributions = Contribution::where('statut', Contribution::STATUT_PENDING)
            ->whereNotNull('payment_reference')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->get();

        foreach ($staleContributions as $contribution) {
            $paymentData = $notchPayGateway->verifyPayment($contribution->payment_reference);
            $rawStatus = $paymentData['status'] ?? null;
            $internalStatus = $rawStatus ? $notchPayGateway->mapStatus($rawStatus) : null;

            if (in_array($internalStatus, ['completed', 'failed'], true)) {
                // NotchPay a une réponse définitive — traitée par le chemin
                // idempotent habituel (crédite si completed, jamais perdue).
                PaymentEventRecorder::recordSyncLog(null, $contribution->id, $internalStatus, $paymentData);

                $contributionService->processPaymentNotification(array_merge($paymentData, [
                    'reference' => $contribution->payment_reference,
                    'status'    => $rawStatus,
                ]));

                $contribution->refresh();
                if ($contribution->statut !== Contribution::STATUT_PENDING) {
                    PaymentEventRecorder::recordEvent(
                        null,
                        $contribution->id,
                        PaymentEvent::TYPE_EXPIRED,
                        $contribution->statut,
                        $paymentData,
                    );
                }

                Log::info('ExpireStaleContributionsJob: contribution synchronisée avant expiration', [
                    'contribution_id' => $contribution->id,
                    'raw_status'      => $rawStatus,
                ]);
                continue;
            }

            // NotchPay ne confirme rien et le délai est largement dépassé :
            // contribution considérée abandonnée. Même chemin idempotent
            // que le webhook (statut synthétique failed), pas d'écriture directe.
            $syntheticMessage = 'Paiement expiré : aucune confirmation NotchPay après ' . $minutes . ' minutes.';

            $contributionService->processPaymentNotification([
                'reference' => $contribution->payment_reference,
                'status'    => 'failed',
                'message'   => $syntheticMessage,
            ]);

            $contribution->refresh();
            if ($contribution->statut !== Contribution::STATUT_PENDING) {
                PaymentEventRecorder::recordEvent(
                    null,
                    $contribution->id,
                    PaymentEvent::TYPE_EXPIRED,
                    $contribution->statut,
                    ['message' => $syntheticMessage, 'synthetic' => true],
                );
            }

            Log::warning('ExpireStaleContributionsJob: contribution expirée sans confirmation NotchPay', [
                'contribution_id'   => $contribution->id,
                'payment_reference' => $contribution->payment_reference,
                'age_minutes'       => $contribution->created_at->diffInMinutes(now()),
            ]);
        }
    }
}
