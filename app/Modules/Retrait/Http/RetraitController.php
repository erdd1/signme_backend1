<?php

namespace App\Modules\Retrait\Http;

use App\Core\Exceptions\ApiException;
use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Application\Services\RateLimitService;
use App\Modules\Retrait\Application\Services\RetraitService;
use App\Modules\Retrait\Http\Requests\StoreRetraitBeneficiaireRequest;
use App\Modules\Retrait\Http\Requests\StoreRetraitRequest;
use App\Modules\Retrait\Http\Resources\RetraitBeneficiaireResource;
use App\Modules\Retrait\Http\Resources\RetraitResource;
use App\Models\Retrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RetraitController — Module Retrait (pasteur/trésorier uniquement)
 *
 * GET  /retraits/solde              → solde interne disponible (jamais lu depuis
 *                                      NotchPay::Balance, qui est global à la plateforme)
 * GET  /retraits/beneficiaire       → compte de retrait actif (ou null)
 * POST /retraits/beneficiaire       → enregistrer/remplacer le compte de retrait
 * POST /retraits                    → initier un retrait
 * GET  /retraits                    → historique paginé (église)
 * POST /retraits/{uuid}/recheck     → revérifier le statut auprès de NotchPay
 */
class RetraitController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly RetraitService $service,
        private readonly RateLimitService $rateLimitService,
    ) {}

    public function solde(Request $request): JsonResponse
    {
        $solde = $this->service->getSolde($request->user());
        return $this->successResponse(['solde' => $solde, 'currency' => 'XAF'], 'Solde récupéré avec succès.');
    }

    public function showBeneficiaire(Request $request): JsonResponse
    {
        $beneficiaire = $this->service->getBeneficiaireActif($request->user());
        return $this->successResponse(
            $beneficiaire ? (new RetraitBeneficiaireResource($beneficiaire))->toArray($request) : null,
            'Compte de retrait récupéré avec succès.'
        );
    }

    public function storeBeneficiaire(StoreRetraitBeneficiaireRequest $request): JsonResponse
    {
        $data = $request->validated();
        $beneficiaire = $this->service->registerBeneficiaire($request->user(), $data['operator'], $data['phone']);

        return $this->createdResponse(
            (new RetraitBeneficiaireResource($beneficiaire))->toArray($request),
            'Compte de retrait enregistré avec succès.'
        );
    }

    public function store(StoreRetraitRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $this->rateLimitService->ensureWithinLimit('retrait_initiation', (string) $user->id);
            $this->rateLimitService->incrementRateLimit('retrait_initiation', (string) $user->id);
        } catch (ApiException $e) {
            return $this->errorResponse($e->getMessage(), 429);
        }

        $retrait = $this->service->initiateRetrait($user, (int) $request->validated()['montant']);

        return $this->createdResponse(
            (new RetraitResource($retrait))->toArray($request),
            $retrait->statut === Retrait::STATUT_FAILED
                ? 'Le retrait a échoué : ' . $retrait->motif_echec
                : 'Retrait initié avec succès.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $retraits = $this->service->listHistorique($request->user(), $perPage);

        return $this->paginatedResponse(
            $retraits->setCollection(
                $retraits->getCollection()->map(fn ($r) => new RetraitResource($r))
            ),
            'Historique des retraits récupéré avec succès.'
        );
    }

    public function recheck(Request $request, string $uuid): JsonResponse
    {
        $retrait = $this->service->findByUuid($uuid, $request->user());
        $retrait = $this->service->recheck($retrait);

        return $this->successResponse(
            (new RetraitResource($retrait))->toArray($request),
            'Statut revérifié avec succès.'
        );
    }
}
