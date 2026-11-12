<?php

namespace App\Modules\Chant\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Chant\Application\Services\ChantService;
use App\Modules\Chant\Application\Services\ChantsHubTokenService;
use App\Modules\Chant\Http\Requests\SearchChantsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ChantController — Module Chants (répertoire, tous rôles clients)
 *
 * GET /api/v1/chants/search?q=…  → chants dont le titre/artiste ou un extrait
 *                                  de paroles correspond (regroupés par titre)
 * GET /api/v1/chants/{id}        → paroles complètes (strophes, refrains) et versions
 * POST /api/v1/chants/token      → jeton d'accès (JWT, 15 min) et adresse du hub Chants séparé
 */
class ChantController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly ChantService $service,
        private readonly ChantsHubTokenService $hubToken,
    ) {}

    public function token(Request $request): JsonResponse
    {
        return $this->successResponse($this->hubToken->issue($request->user()), 'Jeton délivré avec succès.');
    }

    public function search(SearchChantsRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->search($request->validated('q')),
            'Chants récupérés avec succès.'
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse(
            $this->service->show($id),
            'Chant récupéré avec succès.'
        );
    }
}
