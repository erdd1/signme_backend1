<?php

namespace App\Modules\Assistant\Infrastructure;

use App\Core\Exceptions\ApiException;
use App\Modules\Assistant\Application\Contracts\ChatModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client du format « chat/completions », commun à Groq, Gemini (endpoint
 * compatible OpenAI), Mistral, OpenAI, etc.
 */
class OpenAiCompatibleChatClient implements ChatModel
{
    public function complete(array $messages): string
    {
        $apiKey = config('assistant.api_key');
        if (!$apiKey) {
            Log::error('[Assistant] ASSISTANT_API_KEY manquante');
            throw $this->unavailable();
        }

        try {
            $response = Http::baseUrl(rtrim(config('assistant.base_url'), '/'))
                ->timeout(config('assistant.timeout'))
                ->withToken($apiKey)
                ->acceptJson()
                ->post('/chat/completions', [
                    'model'       => config('assistant.model'),
                    'messages'    => $messages,
                    'max_tokens'  => config('assistant.max_tokens'),
                    'temperature' => config('assistant.temperature'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[Assistant] moteur injoignable', ['error' => $e->getMessage()]);
            throw $this->unavailable();
        }

        if ($response->status() === 429) {
            Log::warning('[Assistant] quota du moteur atteint');
            throw ApiException::serviceUnavailable(
                "L'assistant est très sollicité en ce moment. Réessayez dans quelques minutes."
            );
        }

        if ($response->failed()) {
            Log::warning('[Assistant] moteur en erreur', ['status' => $response->status()]);
            throw $this->unavailable();
        }

        $reply = trim((string) $response->json('choices.0.message.content'));
        if ($reply === '') {
            Log::warning('[Assistant] réponse vide du moteur');
            throw $this->unavailable();
        }

        Log::info('[Assistant] réponse générée', [
            'model'             => config('assistant.model'),
            'prompt_tokens'     => $response->json('usage.prompt_tokens'),
            'completion_tokens' => $response->json('usage.completion_tokens'),
        ]);

        return $reply;
    }

    private function unavailable(): ApiException
    {
        return ApiException::serviceUnavailable(
            "L'assistant est momentanément indisponible. Réessayez dans un instant."
        );
    }
}
