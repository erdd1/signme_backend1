<?php

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Jobs\SyncPaymentStatusJob;

/**
 * Planifie les vérifications NotchPay en file Redis (fallback webhook).
 */
class PaymentPollingDispatcher
{
    public function schedule(int $transactionId, string $paymentReference): void
    {
        $delays = config('payment.polling.delays_seconds', [5, 15, 30, 60]);
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
