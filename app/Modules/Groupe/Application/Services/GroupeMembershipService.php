<?php

namespace App\Modules\Groupe\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Groupe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Appartenance d'un membre à ses groupes et poste dans chacun.
 *
 * Règles (appliquées ici, source unique) :
 * - poste = président et/ou trésorier ; aucun des deux = simple membre ;
 * - un membre ne peut être « simple membre » ET président/trésorier : c'est
 *   le même état (aucun poste coché) ;
 * - un seul président et un seul trésorier par groupe : en désigner un
 *   nouveau fait redevenir simple membre le précédent titulaire du poste.
 *
 * Ces postes sont indépendants du rôle (`users.role`) : le rôle « trésorier »
 * de l'église n'a aucun lien avec le trésorier d'un groupe.
 */
class GroupeMembershipService
{
    /**
     * Extrait des données de requête la table groupeId => postes[].
     * Retourne null si la requête ne touche pas aux groupes.
     *
     * - `groupes` ([{id, postes}]) : postes explicites.
     * - `groupeIds` (anciennes versions de l'app) : appartenance seule, les
     *   postes déjà attribués sont conservés (null = « ne pas changer »).
     *
     * @return array<int, array<int, string>|null>|null
     */
    public function extractMap(array $data): ?array
    {
        if (array_key_exists('groupes', $data)) {
            $map = [];
            foreach ($data['groupes'] ?? [] as $entry) {
                $map[(int) $entry['id']] = array_values(array_unique($entry['postes'] ?? []));
            }
            return $map;
        }

        if (array_key_exists('groupeIds', $data)) {
            $map = [];
            foreach ($data['groupeIds'] ?? [] as $id) {
                $map[(int) $id] = null;
            }
            return $map;
        }

        return null;
    }

    /**
     * Synchronise les groupes d'un membre. Dans une transaction : verrouille
     * chaque groupe concerné, puis applique les postes.
     *
     * @param array<int, array<int, string>|null> $map groupeId => postes (null = conserver)
     */
    public function sync(User $user, array $map): void
    {
        $existants = $user->groupes()->get()->keyBy('id');

        DB::transaction(function () use ($user, $map, $existants) {
            $syncData = [];

            foreach ($map as $groupeId => $postes) {
                $existant = $existants->get($groupeId);

                if ($postes === null) {
                    // Conserver les postes actuels (ou simple membre si nouveau).
                    $syncData[$groupeId] = [
                        'estPresident' => (bool) ($existant?->pivot->estPresident ?? false),
                        'estTresorier' => (bool) ($existant?->pivot->estTresorier ?? false),
                    ];
                    continue;
                }

                $syncData[$groupeId] = [
                    'estPresident' => in_array(Groupe::POSTE_PRESIDENT, $postes, true),
                    'estTresorier' => in_array(Groupe::POSTE_TRESORIER, $postes, true),
                ];
            }

            foreach ($syncData as $groupeId => $flags) {
                // Verrou du groupe : deux désignations simultanées ne peuvent
                // pas produire deux présidents.
                Groupe::whereKey($groupeId)->lockForUpdate()->first();

                foreach (['estPresident', 'estTresorier'] as $colonne) {
                    if ($flags[$colonne]) {
                        DB::table('groupe_user')
                            ->where('groupeId', $groupeId)
                            ->where('userId', '!=', $user->id)
                            ->where($colonne, true)
                            ->update([$colonne => false, 'updated_at' => now()]);
                    }
                }
            }

            $user->groupes()->sync($syncData);
        });
    }

    /**
     * Poste du membre dans le groupe, ou null s'il n'en est pas membre.
     *
     * @return array{estPresident: bool, estTresorier: bool}|null
     */
    public function posteDe(User $user, Groupe $groupe): ?array
    {
        $pivot = DB::table('groupe_user')
            ->where('groupeId', $groupe->id)
            ->where('userId', $user->id)
            ->first();

        if (!$pivot) {
            return null;
        }

        return [
            'estPresident' => (bool) $pivot->estPresident,
            'estTresorier' => (bool) $pivot->estTresorier,
        ];
    }

    /**
     * @throws ApiException si le membre n'appartient pas au groupe
     * @return array{estPresident: bool, estTresorier: bool}
     */
    public function exigerMembre(User $user, Groupe $groupe): array
    {
        $poste = $this->posteDe($user, $groupe);
        if ($poste === null || $groupe->estArchive()) {
            // 404 plutôt que 403 : n'indique pas l'existence d'un groupe
            // dont on n'est pas membre.
            throw ApiException::notFound('Groupe');
        }

        return $poste;
    }
}
