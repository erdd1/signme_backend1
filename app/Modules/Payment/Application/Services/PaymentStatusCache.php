<?php

namespace App\Modules\Payment\Application\Services;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Cache;

/**
 * Cache Redis du statut paiement pour polling mobile ultra-léger.
 */
class PaymentStatusCache
{
    public static function key(int $transactionId): string
    {
        return config('payment.cache_prefix') . $transactionId;
    }

    public static function putFromTransaction(PaymentTransaction $transaction): void
    {
        self::put($transaction->id, self::payloadFromModel($transaction));
    }

    public static function put(int $transactionId, array $payload): void
    {
        Cache::put(
            self::key($transactionId),
            $payload,
            now()->addSeconds(config('payment.cache_ttl_seconds'))
        );
    }

    public static function get(int $transactionId): ?array
    {
        $data = Cache::get(self::key($transactionId));

        return is_array($data) ? $data : null;
    }

    public static function forget(int $transactionId): void
    {
        Cache::forget(self::key($transactionId));
    }

    public static function payloadFromModel(PaymentTransaction $transaction): array
    {
        $progress = match ($transaction->status) {
            'pending'    => 0.25,
            'processing' => 0.55,
            'completed'  => 1.0,
            'failed'     => 0.0,
            default      => 0.25,
        };

        return [
            'status'            => $transaction->status,
            'progress'          => $progress,
            'payment_reference' => $transaction->payment_reference,
            'total_amount'      => $transaction->amountCharged ?? $transaction->totalAmount,
            'updated_at'        => now()->toIso8601String(),
        ];
    }
}
