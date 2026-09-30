<?php

namespace App\Modules\Celebration\Http\Admin;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Celebration\Application\Services\CelebrationTypeService;
use App\Modules\Celebration\Http\Requests\UpdateCelebrationTypeRequest;
use App\Modules\Celebration\Http\Resources\CelebrationTypeResource;
use Illuminate\Http\JsonResponse;

/**
 * CelebrationTypeController — Admin uniquement
 *
 * GET /api/admin/celebration-types        → catalogue des 9 occasions
 * PUT /api/admin/celebration-types/{id}   → modifier titre/message/activation
 */
class CelebrationTypeController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly CelebrationTypeService $service,
    ) {}

    public function index(): JsonResponse
    {
        $types = $this->service->listTypes();

        return $this->successResponse(
            CelebrationTypeResource::collection($types),
            'Types de célébration récupérés avec succès.'
        );
    }

    public function update(UpdateCelebrationTypeRequest $request, int $id): JsonResponse
    {
        $type = $this->service->findType($id);
        $type = $this->service->updateType($type, $request->validated());

        return $this->successResponse(
            new CelebrationTypeResource($type),
            'Type de célébration mis à jour avec succès.'
        );
    }
}
