<?php

namespace App\Modules\Chant\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Chant\Application\Services\ChantsHubTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ChantController — accès au hub Chants (tous rôles clients)
 *
 * La recherche, les liens de chants, l'historique et les favoris vivent dans le
 * HUB CHANTS, un service séparé (autre base, autre conteneur). L'API principale
 * ne fait que délivrer le jeton d'accès et l'adresse du hub :
 *
 * POST /api/v1/chants/token → jeton JWT (15 min) et `hub_url`
 */
class ChantController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ChantsHubTokenService $hubToken) {}

    public function token(Request $request): JsonResponse
    {
        return $this->successResponse($this->hubToken->issue($request->user()), 'Jeton délivré avec succès.');
    }
}
