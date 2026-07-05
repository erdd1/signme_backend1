<?php

namespace App\Modules\UserLookup\Http;

use App\Modules\UserLookup\Application\Services\AbstractLookupService;
use App\Modules\UserLookup\Application\Services\QuartierService;
use App\Modules\UserLookup\Http\Requests\CreateQuartierRequest;
use Illuminate\Http\JsonResponse;

/**
 * QuartierController — Module UserLookup
 *
 * GET    /api/v1/quartiers        → Liste des quartiers de l'église [tous rôles]
 * POST   /api/v1/quartiers        → Créer un quartier [pasteur, secretaire]
 * DELETE /api/v1/quartiers/{id}   → Supprimer un quartier [pasteur, secretaire]
 */
class QuartierController extends AbstractLookupController
{
    public function __construct(
        private readonly QuartierService $quartierService
    ) {}

    protected function service(): AbstractLookupService
    {
        return $this->quartierService;
    }

    protected function resourceLabelPluriel(): string
    {
        return 'Quartiers';
    }

    public function store(CreateQuartierRequest $request): JsonResponse
    {
        $quartier = $this->quartierService->create($request->user(), $request->validated());

        return $this->createdResponse($quartier, 'Quartier créé avec succès.');
    }
}
