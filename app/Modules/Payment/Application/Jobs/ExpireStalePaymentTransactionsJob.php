<?php

namespace App\Modules\Payment\Application\Jobs;

use App\Models\PaymentEvent;
use App\Models\PaymentTransaction;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Signature\Application\Services\SignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job planifié — Expire les transactions de paiement (signature à distance)
 * restées "pending" bien au-delà du délai de polling normal (ex. worker de
 * queue tombé en panne, webhook jamais reçu).
 *
 * Garde-fou : avant d'expirer quoi que ce soit, une dernière vérification
 * est faite directement auprès de NotchPay via {@see NotchPayGateway::verifyPayment()}.
 * Si NotchPay confirme un paiement complete, il est traité normalement par
 * {@see SignatureService::processPaymentNotification()} (jamais perdu, jamais
 * marqué en échec). Seules les transactions dont NotchPay ne confirme ni
 * succès ni échec après le délai sont marquées "failed" — et ce, via le même
 * chemin idempotent que le webhook, pas une écriture directe.
 *
 * À exécuter via scheduler : Schedule::job(...)->everyFiveMinutes();
 */
class ExpireStalePaymentTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        NotchPayGateway $notchPayGateway,
        SignatureService $signatureService,
    ): void {
        $minutes = (int) config('payment.pending_expiry_minutes', 15);

        $staleTransactions = PaymentTransaction::where('status', 'pending')
            ->whereNotNull('payment_reference')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->get();

        foreach ($staleTransactions as $transaction) {
            $paymentData = $notchPayGateway->verifyPayment($transaction->payment_reference);
            $rawStatus = $paymentData['status'] ?? null;
            $internalStatus = $rawStatus ? $notchPayGateway->mapStatus($rawStatus) : null;

            if (in_array($internalStatus, ['completed', 'failed'], true)) {
                // NotchPay a une réponse définitive — traitée par le chemin
                // idempotent habituel (crédite si completed, jamais perdu).
                PaymentEventRecorder::recordSyncLog($transaction->id, null, $internalStatus, $paymentData);

                $signatureService->processPaymentNotification(array_merge($paymentData, [
                    'reference' => $transaction->payment_reference,
                    'status'    => $rawStatus,
                ]));

                $transaction->refresh();
                if ($transaction->status !== 'pending') {
                    PaymentEventRecorder::recordEvent(
                        $transaction->id,
                        null,
                        PaymentEvent::TYPE_EXPIRED,
                        $transaction->status,
                        $paymentData,
                    );
                }

                Log::info('ExpireStalePaymentTransactionsJob: transaction synchronisée avant expiration', [
                    'transaction_id' => $transaction->id,
                    'raw_status'     => $rawStatus,
                ]);
                continue;
            }

            // NotchPay ne confirme rien (indisponible, référence inconnue...) et
            // le délai est largement dépassé : paiement considéré abandonné.
            // On passe par processPaymentNotification (statut synthétique
            // failed) pour rester sur le même chemin idempotent que le webhook.
            $syntheticMessage = 'Paiement expiré : aucune confirmation NotchPay après ' . $minutes . ' minutes.';

            $signatureService->processPaymentNotification([
                'reference' => $transaction->payment_reference,
                'status'    => 'failed',
                'message'   => $syntheticMessage,
            ]);

            $transaction->refresh();
            if ($transaction->status !== 'pending') {
                PaymentEventRecorder::recordEvent(
                    $transaction->id,
                    null,
                    PaymentEvent::TYPE_EXPIRED,
                    $transaction->status,
                    ['message' => $syntheticMessage, 'synthetic' => true],
                );
            }

            Log::warning('ExpireStalePaymentTransactionsJob: transaction expirée sans confirmation NotchPay', [
                'transaction_id'    => $transaction->id,
                'payment_reference' => $transaction->payment_reference,
                'age_minutes'       => $transaction->created_at->diffInMinutes(now()),
            ]);
        }
    }
}
