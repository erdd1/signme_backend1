<?php

namespace App\Modules\Support\Http;

use App\Core\Exceptions\ApiException;
use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * SupportController — Contact du service client.
 *
 * GET /api/v1/support/whatsapp → Lien WhatsApp du support [tous rôles]
 *
 * Le numéro WhatsApp est servi depuis la configuration serveur
 * (config('services.whatsapp.support_number')) plutôt que codé en dur dans
 * l'application mobile : modifiable à tout moment sans nouvelle publication.
 */
class SupportController extends Controller
{
    use ApiResponseTrait;

    public function whatsapp(): JsonResponse
    {
        $number = config('services.whatsapp.support_number');

        if (!$number) {
            throw ApiException::serviceUnavailable('Le contact du service client est momentanément indisponible.');
        }

        return $this->successResponse(
            ['link' => "https://wa.me/{$number}"],
            'Lien WhatsApp du service client récupéré avec succès.'
        );
    }
}
