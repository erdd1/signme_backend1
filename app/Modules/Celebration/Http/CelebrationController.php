<?php

namespace App\Modules\Celebration\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Celebration\Application\Services\CelebrationService;
use App\Modules\Celebration\Application\Services\CelebrationTypeService;
use App\Modules\Celebration\Http\Resources\CelebrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CelebrationController — Module Célébrations
 *
 * CLIENT (tous rôles) :
 * GET  /api/v1/celebrations           → mes célébrations, paginé
 * GET  /api/v1/celebrations/{uuid}     → une célébration (pour le deep-link push)
 * POST /api/v1/celebrations/{uuid}/read → marquer comme lue
 *
 * ADMIN :
 * GET    /api/admin/celebrations          → toutes les célébrations envoyées
 * DELETE /api/admin/celebrations/{uuid}   → supprimer une instance (nettoyage)
 */
class CelebrationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly CelebrationService $service,
        private readonly CelebrationTypeService $typeService,
    ) {}

    // ── CLIENT ───────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $celebrations = $this->service->listForUser($request->user(), $perPage);

        return $this->paginatedResponse(
            $celebrations->setCollection(
                $celebrations->getCollection()->map(fn ($c) => new CelebrationResource($c))
            ),
            'Célébrations récupérées avec succès.'
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $celebration = $this->service->findByUuid($uuid, $request->user());

        return $this->successResponse(
            new CelebrationResource($celebration),
            'Célébration récupérée avec succès.'
        );
    }

    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $celebration = $this->service->markAsRead($uuid, $request->user());

        return $this->successResponse(
            new CelebrationResource($celebration),
            'Célébration marquée comme lue.'
        );
    }

    // ── ADMIN ────────────────────────────────────────────────────────────

    public function adminIndex(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $filters = $request->only(['churchId', 'celebrationTypeId']);
        $celebrations = $this->typeService->listInstances($filters, $perPage);

        return $this->paginatedResponse(
            $celebrations->setCollection(
                $celebrations->getCollection()->map(fn ($c) => new CelebrationResource($c))
            ),
            'Célébrations récupérées avec succès.'
        );
    }

    public function adminDestroy(string $uuid): JsonResponse
    {
        $celebration = $this->typeService->findInstance($uuid);
        $this->typeService->deleteInstance($celebration);

        return $this->successResponse(null, 'Célébration supprimée avec succès.');
    }
}
