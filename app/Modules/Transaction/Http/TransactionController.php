<?php

namespace App\Modules\Transaction\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Transaction\Application\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TransactionController — « Mes transactions » (tous rôles clients)
 *
 * GET /api/v1/transactions?page=&per_page=&q=&statut=&type=
 *   → toutes les transactions financières de l'utilisateur connecté
 *     (contributions, dons, signatures — Mobile Money, carte, espèces),
 *     de la plus récente à la plus ancienne.
 */
class TransactionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly TransactionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q'        => ['nullable', 'string', 'max:100'],
            'statut'   => ['nullable', Rule::in(['completed', 'pending', 'failed'])],
            'type'     => ['nullable', Rule::in([
                TransactionService::TYPE_CONTRIBUTION,
                TransactionService::TYPE_DON,
                TransactionService::TYPE_SIGNATURE,
            ])],
        ]);

        return $this->paginatedResponse(
            $this->service->list(
                $request->user(),
                $validated,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 20),
            ),
            'Transactions récupérées avec succès.'
        );
    }
}
