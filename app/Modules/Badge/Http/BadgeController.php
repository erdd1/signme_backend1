<?php

namespace App\Modules\Badge\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Badge\Application\Services\BadgeService;
use App\Modules\Badge\Http\Requests\MarkBadgeReadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BadgeController — Gestion des compteurs de badges non lus
 *
 * GET  /api/v1/notifications/badges            → Retourne les compteurs
 * POST /api/v1/notifications/badges/mark-read  → Marque un type comme lu
 */
class BadgeController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly BadgeService $badgeService
    ) {}

    /**
     * GET /api/v1/notifications/badges
     *
     * Retourne les compteurs de badges pour l'utilisateur connecté.
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "publications": 3,
     *     "historique": 2,
     *     "historique_signatures": 1
     *   }
     * }
     */
    public function badges(Request $request): JsonResponse
    {
        $counts = $this->badgeService->getBadgeCounts($request->user());

        return $this->successResponse($counts, 'Compteurs de badges récupérés.');
    }

    /**
     * POST /api/v1/notifications/badges/mark-read
     *
     * Marque un type de badge comme lu (reset le compteur).
     *
     * @bodyParam type string required  publications | historique | historique_signatures
     */
    public function markRead(MarkBadgeReadRequest $request): JsonResponse
    {
        $this->badgeService->markAsRead(
            $request->user(),
            $request->validated('type')
        );

        // Retourne les nouveaux compteurs après marquage (évite un 2e appel côté Flutter)
        $counts = $this->badgeService->getBadgeCounts($request->user()->fresh());

        return $this->successResponse($counts, 'Badge marqué comme lu.');
    }
}
