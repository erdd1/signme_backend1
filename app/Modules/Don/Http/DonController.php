<?php

namespace App\Modules\Don\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Application\Services\RateLimitService;
use App\Modules\Don\Application\Services\DonService;
use App\Modules\Evenement\Http\Requests\CreateContributionRequest;
use App\Modules\Evenement\Http\Resources\ContributionResource;
use Illuminate\Http\JsonResponse;

/**
 * DonController — Service « Faire un don » (tous rôles clients)
 *
 * POST /api/v1/dons  → mêmes champs et même réponse qu'une contribution
 *   (POST /evenements/{uuid}/contributions) ; le suivi du paiement reste
 *   GET /api/v1/contributions/transaction/{uuid}/status.
 */
class DonController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly DonService $service,
        private readonly RateLimitService $rateLimitService,
    ) {}

    public function store(CreateContributionRequest $request): JsonResponse
    {
        $this->rateLimitService->ensureWithinLimit('payment_initiation', (string) $request->user()->id);
        $this->rateLimitService->incrementRateLimit('payment_initiation', (string) $request->user()->id);

        $contribution = $this->service->initiate($request->user(), $request->validated());

        return $this->createdResponse(
            (new ContributionResource($contribution))->toArray($request),
            'Paiement initié avec succès. Confirmez sur votre téléphone.'
        );
    }
}
