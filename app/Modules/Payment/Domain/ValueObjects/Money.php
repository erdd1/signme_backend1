<?php

namespace App\Modules\Payment\Domain\ValueObjects;

/**
 * Class Money
 * 
 * Value Object pour représenter une somme d'argent
 * Immutable et encapsule la logique de manipulation monétaire
 */
class Money
{
    private int $amount;      // Montant en centimes/unités
    private string $currency;

    public function __construct(int $amount, string $currency = 'XOF')
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        $this->amount = $amount;
        $this->currency = $currency;
    }

    /**
     * Retourne le montant en unités de base
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * Retourne la devise
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Ajouter un montant
     */
    public function add(Money $money): Money
    {
        if ($money->currency !== $this->currency) {
            throw new \InvalidArgumentException('Cannot add different currencies');
        }

        return new Money($this->amount + $money->amount, $this->currency);
    }

    /**
     * Soustraire un montant
     */
    public function subtract(Money $money): Money
    {
        if ($money->currency !== $this->currency) {
            throw new \InvalidArgumentException('Cannot subtract different currencies');
        }

        return new Money($this->amount - $money->amount, $this->currency);
    }

    /**
     * Comparer avec un autre Money
     */
    public function equals(Money $money): bool
    {
        return $this->amount === $money->amount && $this->currency === $money->currency;
    }

    /**
     * Vérifier si supérieur à
     */
    public function greaterThan(Money $money): bool
    {
        if ($money->currency !== $this->currency) {
            throw new \InvalidArgumentException('Cannot compare different currencies');
        }

        return $this->amount > $money->amount;
    }

    /**
     * Vérifier si inférieur à
     */
    public function lessThan(Money $money): bool
    {
        if ($money->currency !== $this->currency) {
            throw new \InvalidArgumentException('Cannot compare different currencies');
        }

        return $this->amount < $money->amount;
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return "{$this->amount} {$this->currency}";
    }
}
