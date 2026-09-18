<?php

namespace App\Modules\Celebration\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Celebration;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Service Celebration — Côté client ("Mes célébrations"). Lecture seule :
 * les instances sont créées uniquement par SendCelebrationPushesJob, jamais
 * par l'utilisateur.
 */
class CelebrationService
{
    public function listForUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Celebration::where('userId', $user->id)
            ->with(['type', 'church', 'user'])
            ->orderByDesc('occursOn')
            ->paginate($perPage);
    }

    /** @throws ApiException si non trouvée ou n'appartient pas à l'utilisateur */
    public function findByUuid(string $uuid, User $user): Celebration
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Célébration');
        }

        $celebration = Celebration::with(['type', 'church', 'user'])
            ->where('uuid', $uuid)
            ->where('userId', $user->id)
            ->first();

        if (!$celebration) {
            throw ApiException::notFound('Célébration');
        }

        return $celebration;
    }

    public function markAsRead(string $uuid, User $user): Celebration
    {
        $celebration = $this->findByUuid($uuid, $user);
        $celebration->markAsRead();

        return $celebration;
    }

    public function unreadCount(User $user): int
    {
        return Celebration::where('userId', $user->id)->where('isRead', false)->count();
    }
}
