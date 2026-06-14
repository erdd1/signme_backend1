<?php

namespace App\Modules\Payment\Domain\ValueObjects;

/**
 * Class PaymentStatus
 * 
 * Value Object pour les statuts de paiement
 * Assure que seuls des statuts valides sont utilisés
 */
class PaymentStatus
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    private string $status;

    private array $validStatuses = [
        self::PENDING,
        self::COMPLETED,
        self::FAILED,
    ];

    public function __construct(string $status)
    {
        if (!in_array($status, $this->validStatuses)) {
            throw new \InvalidArgumentException("Invalid payment status: {$status}");
        }

        $this->status = $status;
    }

    public function getValue(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function canTransitionTo(PaymentStatus $newStatus): bool
    {
        // pending peut aller à completed ou failed
        if ($this->status === self::PENDING) {
            return $newStatus->status === self::COMPLETED || $newStatus->status === self::FAILED;
        }

        // completed/failed ne peuvent pas changer
        return false;
    }

    public function equals(PaymentStatus $other): bool
    {
        return $this->status === $other->status;
    }

    public function __toString(): string
    {
        return $this->status;
    }
}
