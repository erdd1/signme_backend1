<?php

namespace App\Modules\Groupe\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Groupe;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Accès d'un membre à SES groupes (service « Groupes » de l'application).
 *
 * Seuls les membres d'un groupe y accèdent — jamais le pasteur ou la
 * secrétaire en tant que tels (leur rôle ne donne aucun droit ici). Le poste
 * (président / trésorier) est celui du groupe, jamais `users.role`.
 */
class GroupeAccessService
{
    public function __construct(private readonly GroupeMembershipService $membership) {}

    /** Groupes (non archivés) du membre, avec son poste. */
    public function listPourMembre(User $user)
    {
        return $user->groupes()
            ->whereNull('groupes.archivedAt')
            ->withCount('membres')
            ->orderBy('groupes.nom')
            ->get();
    }

    /**
     * Groupe + poste du membre. 404 si le groupe n'existe pas, est archivé ou
     * si l'utilisateur n'en est pas membre.
     *
     * @return array{0: Groupe, 1: array{estPresident: bool, estTresorier: bool}}
     */
    public function charger(User $user, string $groupeUuid): array
    {
        // PostgreSQL rejette un uuid mal formé par une erreur SQL : 404 propre avant.
        if (!Str::isUuid($groupeUuid)) {
            throw ApiException::notFound('Groupe');
        }

        $groupe = Groupe::where('uuid', $groupeUuid)->first();
        if (!$groupe) {
            throw ApiException::notFound('Groupe');
        }

        return [$groupe, $this->membership->exigerMembre($user, $groupe)];
    }

    /** @return array{0: Groupe, 1: array{estPresident: bool, estTresorier: bool}} */
    public function chargerPresident(User $user, string $groupeUuid): array
    {
        [$groupe, $poste] = $this->charger($user, $groupeUuid);
        if (!$poste['estPresident']) {
            throw ApiException::forbidden('Seul le président du groupe peut effectuer cette action.');
        }

        return [$groupe, $poste];
    }

    /** Président ou trésorier (gestion de la caisse). @return array{0: Groupe, 1: array{estPresident: bool, estTresorier: bool}} */
    public function chargerResponsable(User $user, string $groupeUuid): array
    {
        [$groupe, $poste] = $this->charger($user, $groupeUuid);
        if (!$poste['estPresident'] && !$poste['estTresorier']) {
            throw ApiException::forbidden('Seuls le président et le trésorier du groupe peuvent effectuer cette action.');
        }

        return [$groupe, $poste];
    }
}
