<?php

namespace App\Modules\Signature\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Core\Helpers\FeeCalculator;
use App\Models\Signature;
use App\Modules\Signature\Application\Services\SignatureService;
use App\Modules\Signature\Http\Requests\CreateSignatureDistanceRequest;
use App\Modules\Signature\Http\Requests\CreateSignaturePresentielRequest;
use App\Modules\Auth\Application\Services\RateLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SignatureController — Module Signature
 *
 * POST  /api/v1/signatures/distance          → Initier signature à distance (paroissien)
 * POST  /api/v1/signatures/presentiel        → Signature présentielle (ancien/pasteur/admin)
 * GET   /api/v1/signatures/ma-carte          → Ma carte personnelle
 * GET   /api/v1/signatures/historique        → Historique global (admin/pasteur)
 * GET   /api/signatures/fees                 → Grille des frais [public]
 *
 * ADMIN CRUD :
 * GET    /api/admin/signatures               → index
 * POST   /api/admin/signatures               → store
 * GET    /api/admin/signatures/{id}          → show
 * PUT    /api/admin/signatures/{id}          → update
 * DELETE /api/admin/signatures/{id}          → destroy
 * POST   /api/admin/signatures/{id}/refund   → refund
 */
class SignatureController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly SignatureService $signatureService,
        private readonly RateLimitService $rateLimitService,
    ) {}

    /**
     * POST /api/v1/signatures/distance
     *
     * Initie une signature à distance avec paiement NotchPay (mobile money ou carte).
     * Retourne une référence de paiement que Flutter affiche ensuite.
     *
     * Body: { months: ["2026-01", "2026-02"], amount: 1000, paymentMethod: "mobile_money", paymentOperator: "ORANGE MONEY", phoneNumber: "699000000" }
     */
    public function initiateDistance(CreateSignatureDistanceRequest $request): JsonResponse
    {
        $this->rateLimitService->ensureWithinLimit('payment_initiation', (string) $request->user()->id);
        $this->rateLimitService->incrementRateLimit('payment_initiation', (string) $request->user()->id);

        $result = $this->signatureService->initiateDistanceSignature(
            $request->user(),
            $request->validated()
        );

        return $this->createdResponse($result, $result['message']);
    }

    /**
     * GET /api/v1/signatures/transaction/{transactionId}/status
     *
     * Vérifier l'état d'une transaction de paiement (polling backend).
     * Utilisé par Flutter pour mettre à jour la barre de progression.
     */
    public function checkTransactionStatus(Request $request, int $transactionId): JsonResponse
    {
        $status = $this->signatureService->getTransactionStatus(
            $request->user()->id,
            $transactionId
        );

        return $this->successResponse($status, 'État de la transaction récupéré.');
    }

    /**
     * GET /api/v1/signatures/members/{userId}/status
     *
     * Récupère le statut de signature d'un membre pour un mois/année donnés.
     * Params: month (YYYY-MM, optionnel = mois actuel)
     *
     * Réponse: { userId, nom, month, status: 'signé|non_signé', montant?, type? }
     */
    public function getMemberStatus(Request $request, int $userId): JsonResponse
    {
        $authUser = $request->user();
        $user = \App\Models\User::find($userId);

        if (!$user) {
            throw \App\Core\Exceptions\ApiException::notFound('Utilisateur');
        }

        // Vérifier les permissions: non-admin peut voir que sa propre église
        if (!$authUser->isAdmin() && $user->churchId !== $authUser->churchId) {
            throw \App\Core\Exceptions\ApiException::forbidden(
                'Vous n\'avez pas accès aux informations de ce membre.'
            );
        }

        // Récupérer le mois demandé (défaut = mois actuel)
        $monthParam = $request->get('month');
        if (!$monthParam) {
            $now = now();
            $monthParam = $now->format('Y-m');
        }

        [$year, $month] = explode('-', $monthParam);
        $monthNum = (int)$month;

        // Chercher la signature
        $signature = Signature::where('userId', $userId)
            ->where('year', (int)$year)
            ->where('monthIndex', $monthNum - 1)
            ->first();

        $status = $signature ? 'signé' : 'non_signé';

        return $this->successResponse([
            'userId'   => $userId,
            'nom'      => $user->nom,
            'month'    => $monthParam,
            'status'   => $status,
            'amount'   => $signature?->amount,
            'type'     => $signature?->signatureType,
        ], 'Statut de signature récupéré.');
    }

    /**
     * POST /api/v1/signatures/presentiel
     *
     * Enregistre une ou plusieurs signatures présentielle(s).
     * Réservé aux anciens de service, pasteurs et admins.
     *
     * Body: { userId: 5, months: ["2026-03"], amount: 1000 }
     */
    public function signPresentiel(CreateSignaturePresentielRequest $request): JsonResponse
    {
        $signatures = $this->signatureService->signaturePresentielle(
            $request->user(),
            $request->validated()
        );

        $count = count($signatures);
        return $this->createdResponse(
            $signatures,
            "{$count} signature(s) présentielle(s) enregistrée(s) avec succès."
        );
    }

    /**
     * GET /api/v1/signatures/ma-carte
     *
     * Retourne la carte de l'utilisateur connecté pour une année donnée.
     * Query: year (défaut: année courante)
     */
    public function maCarte(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $carte = $this->signatureService->getMaCarte($request->user(), $year);

        return $this->successResponse($carte, 'Carte récupérée avec succès.');
    }

    /**
     * GET /api/v1/signatures/historique
     *
     * Historique paginé des signatures (pasteur/admin).
     * Query: church_id, month (YYYY-MM), year, type (presentiel|distance), search, status, per_page
     */
    public function historique(Request $request): JsonResponse
    {
        $filters = $request->only(['church_id', 'month', 'year', 'type', 'search', 'status']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $data = $this->signatureService->getHistorique($request->user(), $filters, $perPage);

        return $this->paginatedResponse($data, 'Historique récupéré avec succès.');
    }

    /**
     * GET /api/signatures/fees
     *
     * Retourne la grille des frais de service (utile pour affichage Flutter).
     * Route publique (pas besoin d'auth).
     */
    public function feesGrid(): JsonResponse
    {
        return $this->successResponse(
            FeeCalculator::getFeeGrid(),
            'Grille des frais récupérée avec succès.'
        );
    }

    // =========================================================================
    // ADMIN CRUD
    // =========================================================================

    /**
     * GET /api/admin/signatures
     *
     * Liste toutes les signatures avec filtres (admin).
     * Query: church_id, month, year, type, search, status, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['church_id', 'month', 'year', 'type', 'search', 'status']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $data = $this->signatureService->getHistorique($request->user(), $filters, $perPage);

        return $this->paginatedResponse($data, 'Signatures récupérées avec succès.');
    }

    /**
     * POST /api/admin/signatures
     *
     * Créer une signature manuelle (admin uniquement).
     * Body: { userId, months[], amount }
     */
    public function store(CreateSignaturePresentielRequest $request): JsonResponse
    {
        $signatures = $this->signatureService->signaturePresentielle(
            $request->user(),
            $request->validated()
        );

        $count = count($signatures);
        return $this->createdResponse(
            $signatures,
            "{$count} signature(s) créée(s) manuellement par l'administrateur."
        );
    }

    /**
     * GET /api/admin/signatures/{id}
     *
     * Afficher une signature spécifique.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $signature = $this->signatureService->findById($id, $request->user());

        return $this->successResponse($signature, 'Signature récupérée avec succès.');
    }

    /**
     * PUT /api/admin/signatures/{id}
     *
     * Modifier le statut ou le montant d'une signature (admin).
     * Body: { status?: pending|completed|failed, amount?: int, fees?: int }
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:pending,completed,failed'],
            'amount' => ['sometimes', 'integer', 'min:0'],
            'fees'   => ['sometimes', 'integer', 'min:0'],
        ]);

        $signature = $this->signatureService->updateSignature(
            $id,
            $request->only(['status', 'amount', 'fees']),
            $request->user()
        );

        return $this->successResponse($signature, 'Signature mise à jour avec succès.');
    }

    /**
     * DELETE /api/admin/signatures/{id}
     *
     * Supprimer une signature (admin).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->signatureService->deleteSignature($id, $request->user());

        return $this->noContentResponse('Signature supprimée avec succès.');
    }

    /**
     * POST /api/admin/signatures/{id}/refund
     *
     * Remboursement d'une signature (non encore implémenté).
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Remboursement non encore implémenté.',
        ], 501);
    }

    /**
     * GET /api/v1/signatures/historique-personnel
     *
     * Retourne l'historique des signatures à distance de l'utilisateur connecté.
     * Accessible: Tous les utilisateurs
     * Filtre: Année, mois, statut
     * 
     * Query: year, month, status, per_page
     */
    public function getHistoriquePersonnel(Request $request): JsonResponse
    {
        $filters = $request->only(['year', 'month', 'status']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $data = $this->signatureService->getHistoriquePersonnel(
            $request->user(),
            $filters,
            $perPage
        );

        return $this->paginatedResponse($data, 'Historique personnel récupéré avec succès.');
    }

    /**
     * GET /api/v1/signatures/historique-personnel-presentiel
     *
     * Retourne l'historique des signatures en présentiel REÇUES par l'utilisateur.
     * Accessible: Tous les utilisateurs
     * Filtre: Année, mois, statut
     * 
     * Query: year, month, status, per_page
     */
    public function getHistoriquePersonnelPresentiel(Request $request): JsonResponse
    {
        $filters = $request->only(['year', 'month', 'status']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $data = $this->signatureService->getHistoriquePersonnelPresentiel(
            $request->user(),
            $filters,
            $perPage
        );

        return $this->paginatedResponse($data, 'Historique personnel présentiel récupéré avec succès.');
    }

    /**
     * GET /api/v1/signatures/historique-signatures
     *
     * Retourne l'historique des signatures en présentiel effectuées par l'utilisateur.
     * Accessible: Anciens/Pasteurs (role: ancien, pasteur)
     * - Ancien: Voit UNIQUEMENT les signatures qu'il a effectuées
     * - Pasteur: Voit les signatures présentiel de son église
     * Filtre: Année, mois, statut
     * 
     * Query: year, month, status, per_page
     */
    public function getHistoriqueSignatures(Request $request): JsonResponse
    {
        $filters = $request->only(['year', 'month', 'status']);
        $perPage = min((int) $request->get('per_page', 20), 100);

        $data = $this->signatureService->getHistoriqueSignatures(
            $request->user(),
            $filters,
            $perPage
        );

        return $this->paginatedResponse($data, 'Historique des signatures récupéré avec succès.');
    }
}

