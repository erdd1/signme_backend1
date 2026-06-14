<?php

namespace App\Modules\Rapport\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Rapport\Application\Services\RapportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RapportController — Module Rapport
 *
 * GET /api/v1/rapports/secretaire        → Rapport secrétaire (signed/not signed) [sec, pasteur, admin]
 * GET /api/v1/rapports/finances/annuel   → Rapport financier annuel [tresorier, pasteur, admin]
 * GET /api/v1/rapports/finances/mois     → Rapport détail d'un mois
 * GET /api/v1/rapports/comparaison       → Comparaison N vs N-1
 */
class RapportController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly RapportService $rapportService
    ) {}

    /**
     * GET /api/v1/rapports/secretaire
     *
     * Rapport secrétaire : qui a signé / qui n'a pas signé pour un mois.
     * Query: month (YYYY-MM requis), church_id (admin)
     */
    public function secretaire(Request $request): JsonResponse
    {
        $request->validate([
            'month'     => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
        ]);

        $data = $this->rapportService->rapportSecretaire(
            $request->user(),
            $request->month,
            $request->church_id ? (int) $request->church_id : null
        );

        return $this->successResponse($data, 'Rapport secrétaire généré avec succès.');
    }

    /**
     * GET /api/v1/rapports/finances/annuel
     *
     * Rapport financier annuel (12 mois + totaux).
     * Query: year (défaut: année courante), church_id (admin)
     */
    public function financesAnnuel(Request $request): JsonResponse
    {
        $request->validate([
            'year'      => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
        ]);

        $year = (int) $request->get('year', now()->year);

        $data = $this->rapportService->rapportFinancierAnnuel(
            $request->user(),
            $year,
            $request->church_id ? (int) $request->church_id : null
        );

        return $this->successResponse($data, 'Rapport financier annuel généré avec succès.');
    }

    /**
     * GET /api/v1/rapports/finances/mois
     *
     * Rapport financier détail d'un mois (avec distribution des montants).
     * Query: month (YYYY-MM requis), church_id (admin)
     */
    public function financesMois(Request $request): JsonResponse
    {
        $request->validate([
            'month'     => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
        ]);

        $data = $this->rapportService->rapportMois(
            $request->user(),
            $request->month,
            $request->church_id ? (int) $request->church_id : null
        );

        return $this->successResponse($data, 'Rapport mensuel généré avec succès.');
    }

    /**
     * GET /api/v1/rapports/comparaison
     *
     * Comparaison financière N vs N-1 avec pourcentage d'évolution.
     * Query: church_id (admin)
     */
    public function comparaison(Request $request): JsonResponse
    {
        $request->validate([
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
        ]);

        $data = $this->rapportService->rapportComparaison(
            $request->user(),
            $request->church_id ? (int) $request->church_id : null
        );

        return $this->successResponse($data, 'Rapport de comparaison généré avec succès.');
    }
}
