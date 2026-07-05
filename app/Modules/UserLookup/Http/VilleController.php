<?php

namespace App\Modules\UserLookup\Http;

use App\Modules\UserLookup\Application\Services\AbstractLookupService;
use App\Modules\UserLookup\Application\Services\VilleService;
use App\Modules\UserLookup\Http\Requests\CreateVilleRequest;
use Illuminate\Http\JsonResponse;

/**
 * VilleController — Module UserLookup
 *
 * GET    /api/v1/villes        → Liste des villes de l'église [tous rôles]
 * POST   /api/v1/villes        → Créer une ville (nom + estDiaspora) [pasteur, secretaire]
 * DELETE /api/v1/villes/{id}   → Supprimer une ville [pasteur, secretaire]
 */
class VilleController extends AbstractLookupController
{
    public function __construct(
        private readonly VilleService $villeService
    ) {}

    protected function service(): AbstractLookupService
    {
        return $this->villeService;
    }

    protected function resourceLabelPluriel(): string
    {
        return 'Villes';
    }

    public function store(CreateVilleRequest $request): JsonResponse
    {
        $ville = $this->villeService->create($request->user(), $request->validated());

        return $this->createdResponse($ville, 'Ville créée avec succès.');
    }
}
