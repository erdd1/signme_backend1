<?php

namespace App\Modules\Evenement\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Evenement\Application\Services\EvenementService;
use App\Modules\Evenement\Http\Requests\CreateEvenementRequest;
use App\Modules\Evenement\Http\Requests\UpdateEvenementRequest;
use App\Modules\Evenement\Http\Resources\EvenementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EvenementController — Module Evenement (collecte de fonds)
 *
 * GET    /evenements                 → Fil d'actualité [tous]
 * GET    /evenements/{uuid}          → Détail [tous, selon visibilité]
 * POST   /evenements                 → Créer [pasteur, secretaire, admin]
 * PUT    /evenements/{uuid}          → Modifier [auteur ou admin]
 * POST   /evenements/{uuid}/publish            → Publier un brouillon [auteur ou admin]
 * POST   /evenements/{uuid}/cancel-scheduled    → Annuler une publication planifiée [auteur ou admin]
 * POST   /evenements/{uuid}/terminer            → Terminer [auteur ou admin]
 * POST   /evenements/{uuid}/archiver            → Archiver [pasteur, secretaire, admin]
 * POST   /evenements/{uuid}/desarchiver         → Désarchiver [auteur ou admin]
 *
 * Aucune route destroy : suppression jamais exposée (traçabilité légale/comptable).
 */
class EvenementController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly EvenementService $evenementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['church_id', 'statut']);
        $perPage = min((int) $request->get('per_page', 10), 50);

        $evenements = $this->evenementService->list($request->user(), $filters, $perPage);

        return $this->paginatedResponse(
            $evenements->setCollection(
                $evenements->getCollection()->map(fn ($e) => new EvenementResource($e))
            ),
            'Événements récupérés avec succès.'
        );
    }

    /**
     * GET /evenements/gestion — Pasteur/Secrétaire/Trésorier.
     * Tous les événements (Actif ET Archivé, distinctement filtrables via
     * ?statut=), avec montant collecté et nombre de contributeurs distincts.
     */
    public function gestion(Request $request): JsonResponse
    {
        $filters = $request->only(['church_id', 'statut']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $evenements = $this->evenementService->listForGestion($request->user(), $filters, $perPage);

        return $this->paginatedResponse(
            $evenements->setCollection(
                $evenements->getCollection()->map(fn ($e) => new EvenementResource($e))
            ),
            'Événements récupérés avec succès.'
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $evenement = $this->evenementService->findByUuid($request->user(), $uuid);

        return $this->successResponse(
            (new EvenementResource($evenement))->toArray($request),
            'Événement récupéré avec succès.'
        );
    }

    public function store(CreateEvenementRequest $request): JsonResponse
    {
        $evenement = $this->evenementService->create(
            $request->user(),
            $request->validated(),
            $request->file('image')
        );

        return $this->createdResponse(
            (new EvenementResource($evenement))->toArray($request),
            'Événement créé avec succès.'
        );
    }

    public function update(UpdateEvenementRequest $request, string $uuid): JsonResponse
    {
        $evenement = $this->evenementService->update(
            $request->user(),
            $uuid,
            $request->validated(),
            $request->file('image')
        );

        return $this->successResponse(
            (new EvenementResource($evenement))->toArray($request),
            'Événement mis à jour avec succès.'
        );
    }

    public function publish(Request $request, string $uuid): JsonResponse
    {
        $evenement = $this->evenementService->publish($request->user(), $uuid);

        return $this->successResponse((new EvenementResource($evenement))->toArray($request), 'Événement publié avec succès.');
    }

    /**
     * Annule un événement planifié pas encore publié — suppression définitive
     * (jamais visible, aucune contribution possible sur un événement non actif).
     */
    public function cancelScheduled(Request $request, string $uuid): JsonResponse
    {
        $this->evenementService->cancelScheduledPublication($request->user(), $uuid);

        return $this->noContentResponse('Événement supprimé avec succès.');
    }

    public function terminer(Request $request, string $uuid): JsonResponse
    {
        $evenement = $this->evenementService->terminer($request->user(), $uuid);

        return $this->successResponse((new EvenementResource($evenement))->toArray($request), 'Événement terminé avec succès.');
    }

    public function archiver(Request $request, string $uuid): JsonResponse
    {
        $evenement = $this->evenementService->archiver($request->user(), $uuid);

        return $this->successResponse((new EvenementResource($evenement))->toArray($request), 'Événement archivé avec succès.');
    }

    public function desarchiver(Request $request, string $uuid): JsonResponse
    {
        $evenement = $this->evenementService->desarchiver($request->user(), $uuid);

        return $this->successResponse((new EvenementResource($evenement))->toArray($request), 'Événement désarchivé avec succès.');
    }
}
