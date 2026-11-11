<?php

namespace App\Modules\Payment\Application\Services;

use App\Models\Contribution;
use App\Models\PaymentTransaction;
use App\Modules\Payment\Domain\PaymentStatusGateway;
use App\Modules\Payment\Infrastructure\ExternalServices\FlutterwaveGateway;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;

/**
 * Choisit le fournisseur qui a traité un paiement (contribution ou signature).
 * Les paiements antérieurs à l'ajout de la carte n'ont pas de fournisseur renseigné : elles
 * relèvent toutes de NotchPay.
 */
class PaymentGatewayRegistry
{
    public function forContribution(Contribution $contribution): PaymentStatusGateway
    {
        return $this->forGateway($contribution->paymentGateway);
    }

    public function forTransaction(PaymentTransaction $transaction): PaymentStatusGateway
    {
        return $this->forGateway($transaction->paymentGateway);
    }

    private function forGateway(?string $gateway): PaymentStatusGateway
    {
        return match ($gateway) {
            Contribution::GATEWAY_FLUTTERWAVE => app(FlutterwaveGateway::class),
            default                           => app(NotchPayGateway::class),
        };
    }
}
