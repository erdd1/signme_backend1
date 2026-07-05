<?php

namespace App\Modules\Evenement\Http;

use App\Http\Controllers\Controller;
use App\Core\Exceptions\ApiException;
use App\Core\Traits\ApiResponseTrait;
use App\Models\Contribution;
use App\Models\PaymentEvent;
use App\Models\PaymentSyncLog;
use App\Modules\Auth\Application\Services\RateLimitService;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Evenement\Http\Requests\CreateContributionRequest;
use App\Modules\Evenement\Http\Resources\ContributionDetailResource;
use App\Modules\Evenement\Http\Resources\ContributionResource;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Application\Services\PaymentReceiptService;
use App\Modules\Payment\Http\Resources\PaymentEventResource;
use App\Modules\Payment\Http\Resources\PaymentSyncLogResource;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ContributionController — Module Evenement (collecte de fonds)
 *
 * POST /evenements/{uuid}/contributions          → Contribuer [tous rôles]
 * GET  /evenements/{uuid}/contributions           → Vue trésorerie par événement [pasteur, secretaire, tresorier]
 * GET  /contributions/transaction/{uuid}/status   → Statut paiement (polling) [contributeur uniquement]
 * GET  /contributions-evenements                  → Vue trésorerie globale [pasteur, secretaire, tresorier]
 * GET  /admin/contributions/{uuid}/detail         → Détail complet (admin)
 * GET  /admin/contributions/{uuid}/events         → Historique métier (admin)
 * GET  /admin/contributions/{uuid}/sync-logs      → Diagnostic technique (admin)
 * POST /admin/contributions/{uuid}/recheck        → Revérification manuelle (admin)
 */
class ContributionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly ContributionService $contributionService,
        private readonly NotchPayGateway $notchPayGateway,
        private readonly PaymentReceiptService $paymentReceiptService,
        private readonly RateLimitService $rateLimitService,
    ) {}

    public function store(CreateContributionRequest $request, string $uuid): JsonResponse
    {
        $this->rateLimitService->ensureWithinLimit('payment_initiation', (string) $request->user()->id);
        $this->rateLimitService->incrementRateLimit('payment_initiation', (string) $request->user()->id);

        $contribution = $this->contributionService->initiate($request->user(), $uuid, $request->validated());

        $message = $contribution->paymentMethod === Contribution::METHOD_CARD
            ? 'Paiement initié avec succès. Complétez votre paiement par carte.'
            : 'Paiement initié avec succès. Confirmez sur votre téléphone.';

        return $this->createdResponse(
            (new ContributionResource($contribution))->toArray($request),
            $message
        );
    }

    public function status(Request $request, string $uuid): JsonResponse
    {
        $contribution = $this->contributionService->getStatus($request->user(), $uuid);
        $isTerminal = in_array($contribution->statut, [Contribution::STATUT_COMPLETED, Contribution::STATUT_FAILED], true);

        return $this->successResponse([
            ...(new ContributionResource($contribution))->toArray($request),
            'isTerminal'    => $isTerminal,
            'pollIntervalMs' => $isTerminal ? 0 : 3000,
        ], 'Statut récupéré avec succès.');
    }

    public function index(Request $request, string $uuid): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $contributions = $this->contributionService->listForEvenement($request->user(), $uuid, $perPage);

        return $this->paginatedResponse(
            $contributions->setCollection(
                $contributions->getCollection()->map(fn ($c) => new ContributionResource($c))
            ),
            'Contributions récupérées avec succès.'
        );
    }

    public function allForTreasury(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $contributions = $this->contributionService->listAllForTreasury($request->user(), $perPage);

        return $this->paginatedResponse(
            $contributions->setCollection(
                $contributions->getCollection()->map(fn ($c) => new ContributionResource($c))
            ),
            'Contributions récupérées avec succès.'
        );
    }

    // =========================================================================
    // ADMIN — Actions avancées (détail, revérification, logs, reçu)
    // =========================================================================

    public function detail(string $uuid): JsonResponse
    {
        $contribution = $this->contributionService->findByUuidForAdmin($uuid);

        return $this->successResponse(
            ContributionDetailResource::make($contribution),
            'Détail de la contribution récupéré avec succès.'
        );
    }

    public function events(string $uuid): JsonResponse
    {
        $contribution = $this->contributionService->findByUuidForAdmin($uuid);

        $events = PaymentEvent::with('triggeredByAdmin:id,nom')
            ->where('contribution_id', $contribution->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (PaymentEvent $event) => (new PaymentEventResource($event))->resolve());

        return $this->paginatedResponse($events, 'Historique des événements récupéré avec succès.');
    }

    public function syncLogs(string $uuid): JsonResponse
    {
        $contribution = $this->contributionService->findByUuidForAdmin($uuid);

        $logs = PaymentSyncLog::where('contribution_id', $contribution->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (PaymentSyncLog $log) => (new PaymentSyncLogResource($log))->resolve());

        return $this->paginatedResponse($logs, 'Logs de synchronisation récupérés avec succès.');
    }

    public function recheck(Request $request, string $uuid): JsonResponse
    {
        $contribution = $this->contributionService->findByUuidForAdmin($uuid);

        if ($contribution->statut !== Contribution::STATUT_PENDING) {
            throw ApiException::badRequest(
                "Cette contribution est déjà '{$contribution->statut}' — aucune revérification possible sur un statut définitif."
            );
        }

        $paymentData = $this->notchPayGateway->verifyPayment($contribution->payment_reference);

        if ($paymentData === null) {
            PaymentEventRecorder::recordEvent(
                null,
                $contribution->id,
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

        PaymentEventRecorder::recordSyncLog(null, $contribution->id, $internalStatus, $paymentData);
        PaymentEventRecorder::recordEvent(
            null,
            $contribution->id,
            PaymentEvent::TYPE_MANUAL_RECHECK,
            $internalStatus,
            $paymentData,
            $request->user()->id,
            $request->ip(),
        );

        if (in_array($internalStatus, ['completed', 'failed'], true)) {
            $this->contributionService->processPaymentNotification(array_merge($paymentData, [
                'reference' => $contribution->payment_reference,
                'status'    => $rawStatus,
            ]));
        }

        return $this->successResponse(
            ContributionDetailResource::make($contribution->fresh()),
            'Statut revérifié avec succès.'
        );
    }

    /**
     * GET /api/admin/contributions/{uuid}/receipt?download=1
     *
     * Génère le reçu PDF — même document pour "Voir le reçu" (inline) et
     * "Exporter en PDF" (téléchargement), seul le Content-Disposition diffère.
     */
    public function receipt(Request $request, string $uuid)
    {
        $contribution = $this->contributionService->findByUuidForAdmin($uuid);
        $pdf = $this->paymentReceiptService->generateForContribution($contribution);
        $filename = 'recu-' . ($contribution->payment_reference ?? $contribution->uuid) . '.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }
}
