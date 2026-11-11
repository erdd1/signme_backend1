<?php

namespace App\Modules\Payment\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/payments/card-config — paramètres du paiement par carte pour
 * l'application : option disponible ou non (interrupteur serveur) et taux de
 * frais pour l'affichage instantané du total (le serveur recalcule toujours).
 */
class CardPaymentConfigController extends Controller
{
    use ApiResponseTrait;

    public function __invoke(): JsonResponse
    {
        return $this->successResponse([
            'enabled'    => (bool) config('payment.card.enabled'),
            'currency'   => config('payment.card.currency'),
            'feePercent' => (float) config('payment.card.fee_percent'),
        ], 'Paramètres du paiement par carte.');
    }
}
