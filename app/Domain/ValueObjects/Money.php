<?php

namespace App\Domain\ValueObjects;

/**
 * Class Money
 * 
 * Value Object représentant une somme d'argent avec devise
 */
class Money
{
    protected $amount;
    protected $currency;

    public function __construct($amount, $currency = 'XOF')
    {
        $this->amount = (float) $amount;
        $this->currency = $currency;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getCurrency()
    {
        return $this->currency;
    }

    public function equals(Money $other): bool
    {
        return $this->amount === $other->getAmount() 
            && $this->currency === $other->getCurrency();
    }

    public function __toString()
    {
        return "{$this->amount} {$this->currency}";
    }
}
