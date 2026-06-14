<?php

namespace App\Modules\Payment\Domain;

/**
 * Class PaymentInitiated
 * Événement déclenché quand un paiement est initié
 */
class PaymentInitiated
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $userId,
        public readonly int $totalAmount,
        public readonly string $currency,
    ) {}
}

/**
 * Class PaymentCompleted
 * Événement déclenché quand un paiement est complété
 */
class PaymentCompleted
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $userId,
        public readonly array $signatureIds,
        public readonly int $totalAmount,
    ) {}
}

/**
 * Class PaymentFailed
 * Événement déclenché quand un paiement échoue
 */
class PaymentFailed
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $userId,
        public readonly string $reason,
    ) {}
}
