<?php

namespace App\Modules\Signature\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Models\SignatureLog;
use App\Core\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SignatureLogsAdminController — Audit/Traçabilité des signatures (ADMIN ONLY)
 */
class SignatureLogsAdminController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/admin/signature-logs
     * 
     * Récupère tous les logs de signatures avec filtres.
     * Accessible UNIQUEMENT aux admins et pasteurs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SignatureLog::query();

        // Filtrer par utilisateur
        if ($request->has('userId')) {
            $query->where('userId', $request->integer('userId'));
        }

        // Filtrer par église
        if ($request->has('churchId')) {
            $query->where('churchId', $request->integer('churchId'));
        }

        // Filtrer par statut (pending, completed, failed)
        if ($request->has('status')) {
            $query->where('status', $request->string('status'));
        }

        // Filtrer par type (presentiel, distance)
        if ($request->has('signatureType')) {
            $query->where('signatureType', $request->string('signatureType'));
        }

        // Filtrer par plage de dates
        if ($request->has('startDate') && $request->has('endDate')) {
            $query->whereBetween('created_at', [
                $request->date('startDate')->startOfDay(),
                $request->date('endDate')->endOfDay(),
            ]);
        }

        // Pagination
        $perPage = min($request->integer('per_page', 50), 500);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->successResponse($logs, 'Logs de signatures récupérés.');
    }

    /**
     * GET /api/admin/signature-logs/{id}
     * 
     * Récupère les détails d'un log spécifique.
     */
    public function show(int $id): JsonResponse
    {
        $log = SignatureLog::find($id);

        if (!$log) {
            throw ApiException::notFound('Log de signature non trouvé.');
        }

        return $this->successResponse($log, 'Log de signature récupéré.');
    }

    /**
     * GET /api/admin/signature-logs/user/{userId}
     * 
     * Récupère l'historique complet des tentatives de signature pour un utilisateur.
     */
    public function userHistory(int $userId): JsonResponse
    {
        $logs = SignatureLog::where('userId', $userId)
                            ->orderBy('created_at', 'desc')
                            ->get();

        if ($logs->isEmpty()) {
            throw ApiException::notFound('Aucun historique de signature pour cet utilisateur.');
        }

        // Compter par statut
        $stats = [
            'total'      => $logs->count(),
            'completed'  => $logs->where('status', 'completed')->count(),
            'pending'    => $logs->where('status', 'pending')->count(),
            'failed'     => $logs->where('status', 'failed')->count(),
        ];

        return $this->successResponse([
            'user_id' => $userId,
            'stats'   => $stats,
            'logs'    => $logs,
        ], 'Historique utilisateur récupéré.');
    }

    /**
     * GET /api/admin/signature-logs/stats
     * 
     * Statistiques globales des signatures.
     */
    public function stats(Request $request): JsonResponse
    {
        $query = SignatureLog::query();

        // Filtrer par église si fourni
        if ($request->has('churchId')) {
            $query->where('churchId', $request->integer('churchId'));
        }

        // Filtrer par plage de dates
        if ($request->has('startDate') && $request->has('endDate')) {
            $query->whereBetween('created_at', [
                $request->date('startDate')->startOfDay(),
                $request->date('endDate')->endOfDay(),
            ]);
        }

        $stats = [
            'total_attempts'    => $query->clone()->count(),
            'completed'         => $query->clone()->where('status', 'completed')->count(),
            'pending'           => $query->clone()->where('status', 'pending')->count(),
            'failed'            => $query->clone()->where('status', 'failed')->count(),
            'total_amount'      => $query->clone()->where('status', 'completed')->sum('totalAmount'),
            'total_fees'        => $query->clone()->where('status', 'completed')->sum('fees'),
            'by_type'           => [
                'presentiel' => $query->clone()->where('signatureType', 'presentiel')->where('status', 'completed')->count(),
                'distance'   => $query->clone()->where('signatureType', 'distance')->where('status', 'completed')->count(),
            ],
            'by_method'         => $query->clone()
                                    ->select('paymentMethod')
                                    ->where('status', 'completed')
                                    ->groupBy('paymentMethod')
                                    ->selectRaw('paymentMethod, count(*) as count')
                                    ->get()
                                    ->keyBy('paymentMethod')
                                    ->map(fn($item) => $item->count)
                                    ->toArray(),
        ];

        return $this->successResponse($stats, 'Statistiques récupérées.');
    }
}
