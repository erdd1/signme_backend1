<?php

namespace App\Core\Services;

/**
 * Class PaymentGatewayService
 * 
 * Service générique pour intégrer les passerelles de paiement
 */
class PaymentGatewayService
{
    /**
     * Traite un paiement via la passerelle
     */
    public function processPayment($amount, $currency, $paymentMethodId)
    {
        // À implémenter selon la passerelle de paiement choisie
        // (Stripe, PayPal, Orange Money, MTN Mobile Money, etc.)
    }

    /**
     * Récupère le statut d'une transaction
     */
    public function getTransactionStatus($transactionId)
    {
        // À implémenter
    }

    /**
     * Effectue un remboursement
     */
    public function refundPayment($transactionId, $amount = null)
    {
        // À implémenter
    }
}
