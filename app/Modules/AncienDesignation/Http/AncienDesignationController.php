<?php

namespace App\Modules\AncienDesignation\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\AncienDesignation\Application\Services\AncienDesignationService;
use App\Modules\AncienDesignation\Http\Requests\DesignerAncienRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AncienDesignationController — Module AncienDesignation
 *
 * GET    /api/v1/anciens-designations         → Liste anciens du mois [pasteur, admin]
 * POST   /api/v1/anciens-designations         → Désigner un ancien [pasteur, admin]
 * DELETE /api/v1/anciens-designations/{id}    → Retirer la désignation [pasteur, admin]
 * POST   /api/v1/anciens-designations/sync    → Sync flags est_de_service [admin]
 *
 * Règles métier:
 * - Le pasteur peut seulement désigner un ancien pour le mois actuel ou le mois prochain
 * - Un ancien avec jours/horaires spécifiés ne peut signer QUE ces jours/heures
 * - Un ancien sans contrainte (null) peut signer 24/7 tous les jours
 * - Automatiquement retiré le dernier jour du mois de service à 23h59
 * - Un ancien désigné peut signer les mois passés (rattrapage)
 */
class AncienDesignationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly AncienDesignationService $designationService
    ) {}

    /**
     * GET /api/v1/anciens-designations
     *
     * Retourne la liste des anciens désignés ET non désignés pour un mois/école.
     * Query: mois (1-12, default current), annee (YYYY, default current), church_id (admin)
     */
    public function index(Request $request): JsonResponse
    {
        // Default to current month/year if not provided
        $now = \Carbon\Carbon::now();
        
        $request->validate([
            'mois'      => ['sometimes', 'integer', 'min:1', 'max:12'],
            'annee'     => ['sometimes', 'integer', 'min:2020'],
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
        ]);

        $mois = (int) ($request->mois ?? $now->month);
        $annee = (int) ($request->annee ?? $now->year);

        $data = $this->designationService->listByMonthAndChurch(
            $request->user(),
            $mois,
            $annee,
            $request->church_id ? (int) $request->church_id : null
        );

        return $this->successResponse($data, 'Désignations récupérées avec succès.');
    }

    /**
     * POST /api/v1/anciens-designations
     *
     * Désigne un ancien de service pour un mois donné.
     * Body: {
     *   ancien_id: int,
     *   mois: int (1-12),
     *   annee: int,
     *   church_id?: int (admin),
     *   jours_travail?: [1,2,3,4,5,6,7] (null ou vide = 24/7),
     *   horaire_debut?: "HH:MM" (null = 00:00),
     *   horaire_fin?: "HH:MM" (null = 23:59)
     * }
     */
    public function store(DesignerAncienRequest $request): JsonResponse
    {
        $designation = $this->designationService->designer(
            $request->user(),
            $request->ancien_id,
            $request->mois,
            $request->annee,
            $request->church_id,
            $request->jours_travail,
            $request->horaire_debut,
            $request->horaire_fin
        );

        return $this->createdResponse($designation, 'Ancien désigné de service avec succès.');
    }

    /**
     * DELETE /api/v1/anciens-designations/{id}
     *
     * Retire la désignation d'un ancien (et met est_de_service=false si plus désigné ce mois).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->designationService->retirer($request->user(), $id);

        return $this->noContentResponse('Désignation retirée avec succès. L\'ancien n\'est plus de service.');
    }

    /**
     * POST /api/v1/anciens-designations/sync
     *
     * Synchronise les flags est_de_service de tous les anciens d'une église.
     * Utile pour corriger des incohérences.
     * Body: { church_id? }
     */
    public function syncFlags(Request $request): JsonResponse
    {
        $churchId = $request->get('church_id', $request->user()->churchId);

        $this->designationService->syncServiceFlags((int) $churchId);

        return $this->successResponse(null, 'Flags est_de_service synchronisés avec succès.');
    }
}
