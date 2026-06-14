<?php

namespace App\Modules\Publication\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Publication\Application\Services\PublicationService;
use App\Modules\Publication\Http\Requests\CreatePublicationRequest;
use App\Modules\Publication\Http\Requests\UpdatePublicationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PublicationController — Module Publication (Fil d'actualité)
 *
 * GET    /api/v1/publications           → Fil d'actualité [tous]
 * POST   /api/v1/publications           → Créer  [pasteur, secretaire, admin]
 * GET    /api/v1/publications/{id}      → Détail [tous]
 * PUT    /api/v1/publications/{id}      → Modifier [auteur ou admin]
 * DELETE /api/v1/publications/{id}      → Supprimer [auteur, pasteur, admin]
 */
class PublicationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly PublicationService $publicationService
    ) {}

    /**
     * GET /api/v1/publications
     * Query: type (annonce|meditation), church_id (admin), per_page
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'church_id']);
        $perPage = min((int) $request->get('per_page', 10), 50);

        $publications = $this->publicationService->list($request->user(), $filters, $perPage);

        return $this->paginatedResponse($publications, 'Publications récupérées avec succès.');
    }

    /**
     * POST /api/v1/publications
     */
    public function store(CreatePublicationRequest $request): JsonResponse
    {
        $publication = $this->publicationService->create($request->user(), $request->validated());

        return $this->createdResponse($publication, 'Publication créée avec succès.');
    }

    /**
     * GET /api/v1/publications/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $publication = $this->publicationService->findById($request->user(), $id);

        return $this->successResponse($publication, 'Publication récupérée avec succès.');
    }

    /**
     * PUT /api/v1/publications/{id}
     */
    public function update(UpdatePublicationRequest $request, int $id): JsonResponse
    {
        $publication = $this->publicationService->update($request->user(), $id, $request->validated());

        return $this->successResponse($publication, 'Publication mise à jour avec succès.');
    }

    /**
     * DELETE /api/v1/publications/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->publicationService->delete($request->user(), $id);

        return $this->noContentResponse('Publication supprimée avec succès.');
    }
}
