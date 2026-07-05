<?php

namespace App\Modules\UserLookup\Http;

use App\Modules\UserLookup\Application\Services\AbstractLookupService;
use App\Modules\UserLookup\Application\Services\GroupeService;
use App\Modules\UserLookup\Http\Requests\CreateGroupeRequest;
use Illuminate\Http\JsonResponse;

/**
 * GroupeController — Module UserLookup
 *
 * GET    /api/v1/groupes        → Liste des groupes de l'église [tous rôles]
 * POST   /api/v1/groupes        → Créer un groupe [pasteur, secretaire]
 * DELETE /api/v1/groupes/{id}   → Supprimer un groupe [pasteur, secretaire]
 */
class GroupeController extends AbstractLookupController
{
    public function __construct(
        private readonly GroupeService $groupeService
    ) {}

    protected function service(): AbstractLookupService
    {
        return $this->groupeService;
    }

    protected function resourceLabelPluriel(): string
    {
        return 'Groupes';
    }

    public function store(CreateGroupeRequest $request): JsonResponse
    {
        $groupe = $this->groupeService->create($request->user(), $request->validated());

        return $this->createdResponse($groupe, 'Groupe créé avec succès.');
    }
}
