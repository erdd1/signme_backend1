<?php

namespace App\Modules\Assistant\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Assistant\Application\Contracts\ChatModel;
use Illuminate\Support\Facades\Cache;

/**
 * Service Assistant — répond à une conversation, sans rien stocker.
 *
 * Applique les quotas (par utilisateur et global, par jour) avant d'appeler
 * le moteur ; un échec du moteur ne consomme pas le quota de l'utilisateur.
 */
class AssistantService
{
    public function __construct(private readonly ChatModel $model) {}

    /**
     * @param array<int, array{role: string, content: string}> $conversation
     *        du plus ancien au plus récent ; le dernier message est celui de l'utilisateur
     *
     * @throws ApiException
     */
    public function reply(User $user, array $conversation): string
    {
        if (!config('assistant.enabled')) {
            throw ApiException::serviceUnavailable("L'assistant est momentanément indisponible.");
        }

        $recent = array_slice($conversation, -config('assistant.max_messages'));
        $messages = array_merge(
            [['role' => 'system', 'content' => AssistantPrompt::SYSTEM]],
            array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $recent)
        );

        $userKey   = $this->dailyKey("user:{$user->id}");
        $globalKey = $this->dailyKey('global');

        $this->consume($userKey, config('assistant.daily_limit_per_user'),
            ApiException::tooManyRequests(
                "Vous avez atteint votre limite de questions pour aujourd'hui. Revenez demain, que Dieu vous bénisse."
            ));

        try {
            $this->consume($globalKey, config('assistant.daily_limit_global'),
                ApiException::serviceUnavailable(
                    "L'assistant est très sollicité aujourd'hui. Réessayez demain."
                ));

            try {
                return $this->model->complete($messages);
            } catch (ApiException $e) {
                Cache::decrement($globalKey);
                throw $e;
            }
        } catch (ApiException $e) {
            Cache::decrement($userKey);
            throw $e;
        }
    }

    /** Incrémente le compteur ; le retire et lève [$onLimit] si le plafond est dépassé. */
    private function consume(string $key, int $limit, ApiException $onLimit): void
    {
        Cache::add($key, 0, now()->endOfDay()->addHour());

        if (Cache::increment($key) > $limit) {
            Cache::decrement($key);
            throw $onLimit;
        }
    }

    private function dailyKey(string $scope): string
    {
        return 'assistant:daily:' . now()->format('Y-m-d') . ":{$scope}";
    }
}
