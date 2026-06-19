<?php

namespace App\Modules\Payment\Http;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Http\Requests\WebhookRequest;
use App\Modules\Payment\Http\Resources\PaymentResource;
use App\Modules\Payment\Application\PaymentService;
use App\Modules\Payment\Application\DTOs\ProcessPaymentDTO;
use App\Modules\Payment\Infrastructure\ExternalServices\CampayGateway;
use App\Modules\Payment\Application\Jobs\ProcessCampayWebhookJob;
use App\Modules\Signature\Application\Services\SignatureService;
use App\Models\Signature;
use Illuminate\Http\Request;
use Exception;

/**
 * Class PaymentController
 * 
 * Contrôleur pour les endpoints de paiement
 * Webhook HMAC-sécurisé pour Campay
 */
class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private SignatureService $signatureService,
        private CampayGateway $campayGateway,
    ) {}

   
    /**
     * POST /api/v1/webhooks/campay
     *
     * Webhook CENTRAL pour les notifications Campay (sécurisé par HMAC-SHA256).
     *
     * Flux :
     *   Campay → POST /api/v1/webhooks/campay
     *          → valider HMAC
     *          → trouver la référence
     *          → mettre à jour Signature(s) + PaymentTransaction en une transaction
     */
    public function webhook(Request $request)
    {
        try {
            $payload = $request->all();

            \Log::info('Campay Webhook received', [
                'reference' => $payload['reference'] ?? null,
                'status'    => $payload['status'] ?? null,
            ]);

            // ─── 1. Valider la signature JWT (champ "signature" du body) ────────
            if (!$this->campayGateway->validateWebhookSignature($payload)) {
                \Log::warning('Campay Webhook: Signature invalide', [
                    'reference' => $payload['reference'] ?? null,
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
            }

            // ─── 2. Vérifier la référence ─────────────────────────────────────────────
            $campayRef = $payload['reference'] ?? null;
            if (!$campayRef) {
                \Log::warning('Campay Webhook: Référence manquante', $payload);
                return response()->json(['success' => false, 'message' => 'Missing reference'], 400);
            }

            // ─── 3. Traitement asynchrone (file Redis payments) ─────────────────
            ProcessCampayWebhookJob::dispatch($payload);

            \Log::info('Campay Webhook accepté — traitement en file', [
                'reference' => $campayRef,
                'status'    => $payload['status'] ?? null,
            ]);

            return response()->json(['status' => 'accepted'], 200);

        } catch (Exception $e) {
            \Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // ✅ Toujours retourner 200 à Campay pour éviter les retrys infinis
            return response()->json(['status' => 'ok'], 200);
        }
    }

    /**
     * GET /api/v1/payments/{paymentId}
     * Récupérer le statut d'un paiement
     */
    public function show(Request $request, $paymentId)
    {
        try {
            $user = $request->user();

            // Récupérer le paiement
            $paymentEntity = $this->paymentService->getPaymentById($paymentId);

            if (!$paymentEntity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found',
                ], 404);
            }

            // Vérifier que l'utilisateur est propriétaire du paiement
            if ($paymentEntity->getUserId() !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => new PaymentResource($paymentEntity),
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/payments
     * Récupérer les paiements de l'utilisateur
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $filters = [];
            if ($request->has('status')) {
                $filters['status'] = $request->get('status');
            }

            $payments = $this->paymentService->getPaymentsByUser($user->id, $filters);

            return response()->json([
                'success' => true,
                'data' => PaymentResource::collection($payments),
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/payments/refund
     * Demander un remboursement (à implémenter selon vos besoins)
     */
    public function refund(Request $request, $paymentId)
    {
        // À implémenter si nécessaire
        return response()->json([
            'success' => false,
            'message' => 'Not implemented yet',
        ], 501);
    }

    // =========================================================================
    // ADMIN CRUD (manquants — ajoutés pour éviter les erreurs 500)
    // =========================================================================

    /**
     * POST /api/admin/payments
     *
     * Les paiements sont créés automatiquement via le flux Campay.
     * La création manuelle n'est pas supportée par design.
     */
    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'La création manuelle de paiement n\'est pas supportée. '
                       . 'Utilisez le flux Campay via POST /api/v1/signatures/distance.',
        ], 501);
    }

    /**
     * PUT /api/admin/payments/{id}
     *
     * Modifier le statut d'une transaction de paiement (admin).
     * Body: { status: pending|completed|failed }
     */
    public function update(Request $request, $paymentId)
    {
        try {
            $request->validate([
                'status' => ['required', 'in:pending,completed,failed'],
            ]);

            $payment = \App\Models\PaymentTransaction::findOrFail($paymentId);
            $payment->update(['status' => $request->input('status')]);

            \Log::info('Paiement mis à jour par admin', [
                'payment_id' => $paymentId,
                'new_status' => $request->input('status'),
                'updated_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Statut du paiement mis à jour avec succès.',
                'data'    => $payment->fresh(),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/payments/{id}
     *
     * Supprimer une transaction de paiement (admin).
     * Attention : supprime aussi les liens avec les signatures (cascade).
     */
    public function destroy(Request $request, $paymentId)
    {
        try {
            $payment = \App\Models\PaymentTransaction::findOrFail($paymentId);
            $payment->delete();

            \Log::info('Paiement supprimé par admin', [
                'payment_id' => $paymentId,
                'deleted_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Paiement supprimé avec succès.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
