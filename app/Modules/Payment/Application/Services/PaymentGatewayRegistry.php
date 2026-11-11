<?php

namespace App\Modules\Payment\Application\Services;

use App\Models\Contribution;
use App\Modules\Payment\Domain\PaymentStatusGateway;
use App\Modules\Payment\Infrastructure\ExternalServices\FlutterwaveGateway;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;

/**
 * Choisit le fournisseur qui a traité un paiement. Les contributions
 * antérieures à l'ajout de la carte n'ont pas de fournisseur renseigné : elles
 * relèvent toutes de NotchPay.
 */
class PaymentGatewayRegistry
{
    public function forContribution(Contribution $contribution): PaymentStatusGateway
    {
        return match ($contribution->paymentGateway) {
            Contribution::GATEWAY_FLUTTERWAVE => app(FlutterwaveGateway::class),
            default                           => app(NotchPayGateway::class),
        };
    }
}
