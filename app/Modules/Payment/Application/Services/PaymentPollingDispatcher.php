<?php

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Jobs\SyncPaymentStatusJob;

/**
 * Planifie les vérifications NotchPay en file Redis (fallback webhook).
 */
class PaymentPollingDispatcher
{
    /** @param bool $card paiement par carte : vérifications espacées sur ~14 min */
    public function schedule(int $transactionId, string $paymentReference, bool $card = false): void
    {
        $delays = $card
            ? config('payment.polling.card_delays_seconds', [20, 60, 180, 420, 840])
            : config('payment.polling.delays_seconds', [5, 15, 30, 60]);
        $queue  = config('payment.queue', 'payments');
        $conn   = config('payment.connection', 'redis');

        foreach ($delays as $index => $seconds) {
            SyncPaymentStatusJob::dispatch(
                transactionId: $transactionId,
                paymentReference: $paymentReference,
                attempt: $index + 1,
            )
                ->onConnection($conn)
                ->onQueue($queue)
                ->delay(now()->addSeconds((int) $seconds));
        }
    }
}
