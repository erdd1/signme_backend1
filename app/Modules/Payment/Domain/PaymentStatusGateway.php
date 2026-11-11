<?php

namespace App\Modules\Payment\Domain;

/**
 * Ce que le suivi d'un paiement (polling, expiration, webhook) attend d'un
 * fournisseur : vérifier un paiement à partir de sa référence et traduire son
 * statut dans le vocabulaire interne (pending | completed | failed).
 *
 * Implémenté par NotchPayGateway (Mobile Money) et FlutterwaveGateway (carte).
 */
interface PaymentStatusGateway
{
    /**
     * @return array<string, mixed>|null données du paiement (au minimum la clé
     *         `status` brute du fournisseur), null si indisponible ou inconnu
     */
    public function verifyPayment(string $reference): ?array;

    /** @return string 'completed' | 'failed' | 'pending' */
    public function mapStatus(string $providerStatus): string;
}
