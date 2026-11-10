<?php

namespace App\Modules\Assistant\Http;

use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Modules\Assistant\Application\Services\AssistantService;
use App\Modules\Assistant\Http\Requests\ChatRequest;
use Illuminate\Http\JsonResponse;

/**
 * AssistantController — Module Assistant religieux (tous rôles clients)
 *
 * POST /api/v1/assistant/chat  { messages: [{role, content}, …] }
 *   → { reply } — le dernier message est la question de l'utilisateur.
 */
class AssistantController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly AssistantService $service) {}

    public function chat(ChatRequest $request): JsonResponse
    {
        $reply = $this->service->reply($request->user(), $request->validated('messages'));

        return $this->successResponse(['reply' => $reply], 'Réponse générée avec succès.');
    }
}
