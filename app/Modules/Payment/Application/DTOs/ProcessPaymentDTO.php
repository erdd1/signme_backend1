<?php

namespace App\Modules\Payment\Application\DTOs;

/**
 * Class ProcessPaymentDTO
 * 
 * DTO pour le traitement d'un paiement webhook
 */
class ProcessPaymentDTO
{
    public function __construct(
        public readonly string $reference,          // Référence Campay
        public readonly string $status,             // SUCCESSFUL, FAILED, etc.
        public readonly array $metadata,            // Données brutes Campay
    ) {}
}
