<?php

namespace App\Modules\UserLookup\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Groupe;
use App\Models\User;
use App\Modules\Groupe\Application\Services\GroupeCaisseService;
use Illuminate\Support\Collection;

/**
 * Service Groupe — Groupes/ministères de l'église (ex. Chorale, Jeunesse),
 * gérés par le pasteur/la secrétaire.
 *
 * Un groupe qui a déjà manipulé de l'argent (contributions, cash, retraits)
 * ne se supprime plus : il est ARCHIVÉ (disparaît des listes, historique
 * intact). Sans aucun mouvement, la suppression reste possible.
 */
class GroupeService extends AbstractLookupService
{
    public function __construct(private readonly GroupeCaisseService $caisse) {}

    protected function modelClass(): string
    {
        return Groupe::class;
    }

    protected function label(): string
    {
        return 'groupe';
    }

    public function list(User $authUser, ?int $churchId = null): Collection
    {
        $egliseId = $this->resolveChurchId($authUser, $churchId);

        return Groupe::where('churchId', $egliseId)
            ->whereNull('archivedAt')
            ->orderBy('nom')
            ->get();
    }

    public function delete(User $authUser, int $id): void
    {
        $groupe = Groupe::find($id);

        if (!$groupe) {
            throw ApiException::notFound('Groupe');
        }

        if (!$authUser->isAdmin() && $groupe->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Vous ne pouvez pas supprimer ce groupe.');
        }

        if ($this->caisse->aDesMouvements($groupe)) {
            $groupe->update(['archivedAt' => now()]);
            return;
        }

        $groupe->delete();
    }
}
