<?php
namespace App\Modules\Tresorerie\Http;
use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Tresorerie\Application\Services\SortieFinanciereService;
use App\Modules\Tresorerie\Http\Requests\{CreateSortieSimpleRequest, CreateSortieFraisRequest, UpdateSortieSimpleRequest, RejectSortieRequest};
use App\Modules\Tresorerie\Http\Resources\SortieFinanciereResource;
use Illuminate\Http\{JsonResponse, Request};

/**
 * SortieFinanciereController — Module Tresorerie
 *
 * CLIENT (pasteur, tresorier) :
 * GET  /sorties-financieres/dashboard → stats globales + période
 * GET  /sorties-financieres           → liste paginée
 * POST /sorties-financieres/simple    → créer sortie simple
 * POST /sorties-financieres/frais     → créer sortie de frais
 * PUT  /sorties-financieres/{uuid}    → modifier sortie simple
 * DELETE /sorties-financieres/{uuid}  → supprimer
 *
 * ADMIN :
 * GET  /admin/sorties-financieres           → toutes les sorties
 * GET  /admin/sorties-financieres/pending   → sorties frais en attente
 * POST /admin/sorties-financieres/{uuid}/approve → valider
 * POST /admin/sorties-financieres/{uuid}/reject  → rejeter
 */
class SortieFinanciereController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly SortieFinanciereService $service,
    ) {}

    // ── CLIENT ─────────────────────────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $filters = $request->only(['year', 'month']);
        $stats   = $this->service->getDashboardStats($request->user(), $filters);
        return $this->successResponse($stats, 'Tableau de bord récupéré avec succès.');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $filters = $request->only(['type', 'statut', 'year', 'month']);
        $sorties = $this->service->listSorties($request->user(), $filters, $perPage);

        return $this->paginatedResponse(
            $sorties->setCollection(
                $sorties->getCollection()->map(fn ($s) => new SortieFinanciereResource($s))
            ),
            'Sorties récupérées avec succès.'
        );
    }

    public function storeSimple(CreateSortieSimpleRequest $request): JsonResponse
    {
        $sortie = $this->service->createSimple($request->user(), $request->validated());
        return $this->createdResponse(
            (new SortieFinanciereResource($sortie->load(['auteur', 'validatedBy'])))->toArray($request),
            'Sortie financière enregistrée avec succès.'
        );
    }

    public function storeFrais(CreateSortieFraisRequest $request): JsonResponse
    {
        $sortie = $this->service->createFrais($request->user(), $request->validated());
        return $this->createdResponse(
            (new SortieFinanciereResource($sortie->load(['auteur', 'validatedBy'])))->toArray($request),
            'Demande de sortie de frais créée. En attente de validation par l\'administration.'
        );
    }

    public function update(UpdateSortieSimpleRequest $request, string $uuid): JsonResponse
    {
        $sortie = $this->service->findByUuid($uuid, $request->user());
        $sortie = $this->service->updateSimple($request->user(), $sortie, $request->validated());
        return $this->successResponse(
            (new SortieFinanciereResource($sortie))->toArray($request),
            'Sortie mise à jour avec succès.'
        );
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $sortie = $this->service->findByUuid($uuid, $request->user());
        $this->service->delete($request->user(), $sortie);
        return $this->successResponse(null, 'Sortie supprimée avec succès.');
    }

    // ── ADMIN ──────────────────────────────────────────────────────────────────

    public function adminIndex(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $filters = $request->only(['church_id', 'type', 'statut']);
        $sorties = $this->service->listAllForAdmin($filters, $perPage);

        return $this->paginatedResponse(
            $sorties->setCollection(
                $sorties->getCollection()->map(fn ($s) => new SortieFinanciereResource($s))
            ),
            'Sorties récupérées avec succès.'
        );
    }

    public function adminPending(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $sorties = $this->service->listPending($perPage);

        return $this->paginatedResponse(
            $sorties->setCollection(
                $sorties->getCollection()->map(fn ($s) => new SortieFinanciereResource($s))
            ),
            'Sorties en attente récupérées avec succès.'
        );
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        $sortie = $this->service->findByUuid($uuid);
        $sortie = $this->service->approve($request->user(), $sortie);
        return $this->successResponse(
            (new SortieFinanciereResource($sortie))->toArray($request),
            'Sortie de frais validée avec succès.'
        );
    }

    public function reject(RejectSortieRequest $request, string $uuid): JsonResponse
    {
        $sortie = $this->service->findByUuid($uuid);
        $sortie = $this->service->reject($request->user(), $sortie, $request->validated()['motif']);
        return $this->successResponse(
            (new SortieFinanciereResource($sortie))->toArray($request),
            'Sortie de frais rejetée.'
        );
    }
}
