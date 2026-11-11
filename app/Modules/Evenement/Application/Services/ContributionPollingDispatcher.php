<?php

namespace App\Modules\Evenement\Application\Services;

use App\Modules\Evenement\Application\Jobs\SyncEvenementContributionStatusJob;

/**
 * Planifie les vérifications NotchPay en file Redis (fallback webhook) pour
 * les contributions événements. Miroir de
 * App\Modules\Payment\Application\Services\PaymentPollingDispatcher, mêmes
 * délais de config, même queue Redis "payments" — aucune duplication de la
 * logique NotchPay elle-même (NotchPayGateway reste unique et partagé).
 */
class ContributionPollingDispatcher
{
    /** @param bool $card paiement par carte : vérifications espacées sur ~14 min */
    public function schedule(int $contributionId, string $paymentReference, bool $card = false): void
    {
        $delays = $card
            ? config('payment.polling.card_delays_seconds', [20, 60, 180, 420, 840])
            : config('payment.polling.delays_seconds', [5, 15, 30, 60]);
        $queue  = config('payment.queue', 'payments');
        $conn   = config('payment.connection', 'redis');

        foreach ($delays as $index => $seconds) {
            SyncEvenementContributionStatusJob::dispatch(
                contributionId: $contributionId,
                paymentReference: $paymentReference,
                attempt: $index + 1,
            )
                ->onConnection($conn)
                ->onQueue($queue)
                ->delay(now()->addSeconds((int) $seconds));
        }
    }
}
