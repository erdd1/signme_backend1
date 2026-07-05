<?php

namespace App\Modules\Payment\Http;

use App\Http\Controllers\Controller;
use App\Core\Exceptions\ApiException;
use App\Core\Traits\ApiResponseTrait;
use App\Modules\Payment\Http\Requests\WebhookRequest;
use App\Modules\Payment\Http\Resources\PaymentEventResource;
use App\Modules\Payment\Http\Resources\PaymentResource;
use App\Modules\Payment\Http\Resources\PaymentSyncLogResource;
use App\Modules\Payment\Http\Resources\PaymentTransactionDetailResource;
use App\Modules\Payment\Application\PaymentService;
use App\Modules\Payment\Application\DTOs\ProcessPaymentDTO;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Application\Services\PaymentReceiptService;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Payment\Application\Jobs\ProcessPaymentWebhookJob;
use App\Modules\Signature\Application\Services\SignatureService;
use App\Modules\Auth\Application\Services\RateLimitService;
use App\Modules\Evenement\Application\Jobs\ProcessEvenementContributionWebhookJob;
use App\Models\PaymentEvent;
use App\Models\PaymentSyncLog;
use App\Models\PaymentTransaction;
use App\Models\Signature;
use App\Models\Contribution;
use Illuminate\Http\Request;
use Exception;

/**
 * Class PaymentController
 *
 * Contrôleur pour les endpoints de paiement
 * Webhook HMAC-sécurisé pour NotchPay
 */
class PaymentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private PaymentService $paymentService,
        private SignatureService $signatureService,
        private NotchPayGateway $notchPayGateway,
        private PaymentReceiptService $paymentReceiptService,
        private RateLimitService $rateLimitService,
    ) {}


    /**
     * POST /api/v1/webhooks/notchpay
     *
     * Webhook CENTRAL pour les notifications NotchPay (sécurisé par HMAC-SHA256
     * sur le corps brut, header `x-notch-signature`).
     *
     * Flux :
     *   NotchPay → POST /api/v1/webhooks/notchpay
     *            → valider HMAC (corps brut)
     *            → extraire la référence (payload imbriqué sous "data")
     *            → trouver la référence
     *            → mettre à jour Signature(s)/Contribution + PaymentTransaction
     */
    public function webhook(Request $request)
    {
        try {
            $this->rateLimitService->ensureWithinLimit('webhook', $request->ip());
            $this->rateLimitService->incrementRateLimit('webhook', $request->ip());
        } catch (ApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 429);
        }

        try {
            $rawBody = $request->getContent();
            $signatureHeader = $request->header('x-notch-signature');
            $decoded = json_decode($rawBody, true) ?? [];
            $data = $decoded['data'] ?? $decoded;

            \Log::info('NotchPay Webhook received', [
                'event'     => $decoded['event'] ?? null,
                'reference' => $data['reference'] ?? null,
                'status'    => $data['status'] ?? null,
            ]);

            // ─── 1. Valider la signature HMAC-SHA256 (corps brut) ────────────────
            if (!$this->notchPayGateway->validateWebhookSignature($rawBody, $signatureHeader)) {
                \Log::warning('NotchPay Webhook: Signature invalide', [
                    'reference' => $data['reference'] ?? null,
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
            }

            // ─── 2. Vérifier la référence ─────────────────────────────────────────────
            $paymentRef = $data['reference'] ?? null;
            if (!$paymentRef) {
                \Log::warning('NotchPay Webhook: Référence manquante', $decoded);
                return response()->json(['success' => false, 'message' => 'Missing reference'], 400);
            }

            // ─── 3. Traitement asynchrone (file Redis payments) ─────────────────
            // Une référence NotchPay appartient soit à une transaction "signature"
            // (payment_transactions), soit à une contribution événement
            // (contributions) : on route vers le bon job sans dupliquer la
            // vérification de signature ni le gateway NotchPay. Le même lookup
            // sert aussi à enregistrer l'événement d'audit "webhook_received".
            $resultingStatus = $this->notchPayGateway->mapStatus($data['status'] ?? '');
            $contribution = Contribution::where('payment_reference', $paymentRef)->first();

            if ($contribution) {
                PaymentEventRecorder::recordEvent(
                    null,
                    $contribution->id,
                    PaymentEvent::TYPE_WEBHOOK,
                    $resultingStatus,
                    $data,
                );
                ProcessEvenementContributionWebhookJob::dispatch($data);
            } else {
                $transaction = PaymentTransaction::where('payment_reference', $paymentRef)->first();
                if ($transaction) {
                    PaymentEventRecorder::recordEvent(
                        $transaction->id,
                        null,
                        PaymentEvent::TYPE_WEBHOOK,
                        $resultingStatus,
                        $data,
                    );
                }
                ProcessPaymentWebhookJob::dispatch($data);
            }

            \Log::info('NotchPay Webhook accepté — traitement en file', [
                'reference' => $paymentRef,
                'status'    => $data['status'] ?? null,
            ]);

            return response()->json(['status' => 'accepted'], 200);

        } catch (Exception $e) {
            \Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // ✅ Toujours retourner 200 à NotchPay pour éviter les retrys infinis
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
     * Les paiements sont créés automatiquement via le flux NotchPay.
     * La création manuelle n'est pas supportée par design.
     */
    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'La création manuelle de paiement n\'est pas supportée. '
                       . 'Utilisez le flux NotchPay via POST /api/v1/signatures/distance.',
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

            if ($payment->status !== 'pending') {
                throw ApiException::badRequest(
                    "Ce paiement est déjà '{$payment->status}' — statut définitif, modification impossible."
                );
            }

            $newStatus = $request->input('status');
            $payment->update(['status' => $newStatus]);

            PaymentEventRecorder::recordEvent(
                $payment->id,
                null,
                PaymentEvent::TYPE_ADMIN_OVERRIDE,
                $newStatus,
                ['previous_status' => 'pending'],
                $request->user()->id,
                $request->ip(),
            );

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
        } catch (ApiException $e) {
            throw $e;
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

    // =========================================================================
    // ADMIN — Actions avancées (détail, revérification, logs, reçu)
    // =========================================================================

    /**
     * GET /api/admin/payments/{id}/detail
     */
    public function detail(int $paymentId)
    {
        $transaction = PaymentTransaction::findOrFail($paymentId);

        return $this->successResponse(
            PaymentTransactionDetailResource::make($transaction),
            'Détail du paiement récupéré avec succès.'
        );
    }

    /**
     * GET /api/admin/payments/{id}/events
     * Historique métier permanent (payment_events) pour CETTE transaction.
     */
    public function events(int $paymentId)
    {
        PaymentTransaction::findOrFail($paymentId);

        $events = PaymentEvent::with('triggeredByAdmin:id,nom')
            ->where('payment_transaction_id', $paymentId)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (PaymentEvent $event) => (new PaymentEventResource($event))->resolve());

        return $this->paginatedResponse($events, 'Historique des événements récupéré avec succès.');
    }

    /**
     * GET /api/admin/payments/{id}/sync-logs
     * Diagnostic technique (payment_sync_logs, purgé après 7 jours).
     */
    public function syncLogs(int $paymentId)
    {
        PaymentTransaction::findOrFail($paymentId);

        $logs = PaymentSyncLog::where('payment_transaction_id', $paymentId)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (PaymentSyncLog $log) => (new PaymentSyncLogResource($log))->resolve());

        return $this->paginatedResponse($logs, 'Logs de synchronisation récupérés avec succès.');
    }

    /**
     * POST /api/admin/payments/{id}/recheck
     *
     * Revérifie le statut auprès de NotchPay de façon SYNCHRONE. Actif
     * uniquement si le paiement est encore `pending` — un paiement déjà
     * `completed`/`failed` est définitif (immutabilité financière).
     */
    public function recheck(Request $request, int $paymentId)
    {
        $transaction = PaymentTransaction::findOrFail($paymentId);

        if ($transaction->status !== 'pending') {
            throw ApiException::badRequest(
                "Ce paiement est déjà '{$transaction->status}' — aucune revérification possible sur un statut définitif."
            );
        }

        $paymentData = $this->notchPayGateway->verifyPayment($transaction->payment_reference);

        if ($paymentData === null) {
            PaymentEventRecorder::recordEvent(
                $transaction->id,
                null,
                PaymentEvent::TYPE_MANUAL_RECHECK,
                'pending',
                ['message' => 'NotchPay indisponible', 'synthetic' => true],
                $request->user()->id,
                $request->ip(),
            );

            return $this->errorResponse('NotchPay est indisponible pour le moment. Réessayez plus tard.', 503);
        }

        $rawStatus = $paymentData['status'] ?? '';
        $internalStatus = $this->notchPayGateway->mapStatus($rawStatus);

        PaymentEventRecorder::recordSyncLog($transaction->id, null, $internalStatus, $paymentData);
        PaymentEventRecorder::recordEvent(
            $transaction->id,
            null,
            PaymentEvent::TYPE_MANUAL_RECHECK,
            $internalStatus,
            $paymentData,
            $request->user()->id,
            $request->ip(),
        );

        if (in_array($internalStatus, ['completed', 'failed'], true)) {
            $this->signatureService->processPaymentNotification(array_merge($paymentData, [
                'reference' => $transaction->payment_reference,
                'status'    => $rawStatus,
            ]));
        }

        return $this->successResponse(
            PaymentTransactionDetailResource::make($transaction->fresh()),
            'Statut revérifié avec succès.'
        );
    }

    /**
     * GET /api/admin/payments/{id}/receipt?download=1
     *
     * Génère le reçu PDF — même document pour "Voir le reçu" (inline) et
     * "Exporter en PDF" (téléchargement), seul le Content-Disposition diffère.
     */
    public function receipt(Request $request, int $paymentId)
    {
        $transaction = PaymentTransaction::findOrFail($paymentId);
        $pdf = $this->paymentReceiptService->generateForPaymentTransaction($transaction);
        $filename = 'recu-' . ($transaction->payment_reference ?? $transaction->id) . '.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }
}
