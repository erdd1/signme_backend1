<?php

namespace App\Application\DTOs;

/**
 * Class PaymentDTO
 * 
 * Data Transfer Object pour les opérations de paiement
 */
class PaymentDTO
{
    public function __construct(
        public float $amount,
        public string $currency = 'XOF',
        public string $paymentMethodId = '',
        public string $description = '',
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            amount: $data['amount'] ?? 0,
            currency: $data['currency'] ?? 'XOF',
            paymentMethodId: $data['payment_method_id'] ?? '',
            description: $data['description'] ?? '',
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method_id' => $this->paymentMethodId,
            'description' => $this->description,
            'metadata' => $this->metadata,
        ];
    }
}
