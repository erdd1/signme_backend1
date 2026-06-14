<?php

namespace App\Modules\Church\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Church\Application\Services\ChurchService;
use App\Modules\Church\Http\Requests\CreateChurchRequest;
use App\Modules\Church\Http\Requests\UpdateChurchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ChurchController — Module Church
 *
 * Toutes les routes sont protégées et réservées à l'administrateur.
 *
 * GET    /api/v1/churches              → Liste des églises
 * POST   /api/v1/churches              → Créer une église
 * GET    /api/v1/churches/{id}         → Détail + stats
 * PUT    /api/v1/churches/{id}         → Modifier
 * DELETE /api/v1/churches/{id}         → Supprimer
 * POST   /api/v1/churches/{id}/pastor  → Assigner un pasteur
 */
class ChurchController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly ChurchService $churchService
    ) {}

    /**
     * GET /api/v1/churches
     * Query params: search, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search']);
        $perPage = min((int) $request->get('per_page', 15), 100);

        $churches = $this->churchService->list($filters, $perPage);

        return $this->paginatedResponse($churches, 'Églises récupérées avec succès.');
    }

    /**
     * POST /api/v1/churches
     */
    public function store(CreateChurchRequest $request): JsonResponse
    {
        $church = $this->churchService->create($request->validated());

        return $this->createdResponse($church, 'Église créée avec succès.');
    }

    /**
     * GET /api/v1/churches/{id}
     */
    public function show(int $id): JsonResponse
    {
        $data = $this->churchService->findWithStats($id);

        return $this->successResponse($data, 'Église récupérée avec succès.');
    }

    /**
     * PUT /api/v1/churches/{id}
     */
    public function update(UpdateChurchRequest $request, int $id): JsonResponse
    {
        $church = $this->churchService->update($id, $request->validated());

        return $this->successResponse($church, 'Église mise à jour avec succès.');
    }

    /**
     * DELETE /api/v1/churches/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $this->churchService->delete($id);

        return $this->noContentResponse('Église supprimée avec succès.');
    }

    /**
     * POST /api/v1/churches/{id}/pastor
     * Body: { pastor_id }
     */
    public function assignPastor(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'pastor_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $church = $this->churchService->assignPastor($id, $request->pastor_id);

        return $this->successResponse($church, 'Pasteur assigné avec succès.');
    }
}
