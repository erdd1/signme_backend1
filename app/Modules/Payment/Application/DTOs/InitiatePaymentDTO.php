<?php

namespace App\Modules\Payment\Application\DTOs;

/**
 * Class InitiatePaymentDTO
 * 
 * DTO pour l'initiation d'un paiement
 */
class InitiatePaymentDTO
{
    public function __construct(
        public readonly int $userId,
        public readonly int $churchId,
        public readonly string $paymentOperator,    // ORANGE MONEY, MTN MOBILE MONEY
        public readonly string $paymentNumber,      // Numéro de téléphone
        public readonly int $amountPerMonth,        // Montant par mois (XOF)
        public readonly int $monthsCount,           // Nombre de mois à signer
        public readonly int $fees,                  // Frais Campay
        public readonly string $signatureType,      // presentiel, distance
        public readonly array $signatureIds,        // IDs des signatures à lier au paiement
    ) {}
}
