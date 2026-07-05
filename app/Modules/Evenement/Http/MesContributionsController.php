<?php

namespace App\Modules\Evenement\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Evenement\Application\Services\MesContributionsService;
use App\Modules\Evenement\Http\Resources\MesContributionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MesContributionsController — Module "Mes dépenses"
 *
 * GET /mes-contributions        → Historique perso [tous rôles]
 * GET /mes-contributions/stats  → Statistiques (total / ce mois / cette année) [tous rôles]
 */
class MesContributionsController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly MesContributionsService $mesContributionsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['annee', 'mois']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $contributions = $this->mesContributionsService->list($request->user(), $filters, $perPage);

        return $this->paginatedResponse(
            $contributions->setCollection(
                $contributions->getCollection()->map(fn ($c) => new MesContributionResource($c))
            ),
            'Contributions récupérées avec succès.'
        );
    }

    public function stats(Request $request): JsonResponse
    {
        $filters = $request->only(['annee', 'mois']);
        $stats = $this->mesContributionsService->stats($request->user(), $filters);

        return $this->successResponse($stats, 'Statistiques récupérées avec succès.');
    }
}
