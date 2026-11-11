<?php

namespace App\Modules\Groupe\Application\Services;

use App\Models\Contribution;
use App\Models\Groupe;
use App\Models\GroupeEntreeCash;
use App\Models\Retrait;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Badges de notification des groupes : pour chaque groupe d'un membre, le
 * nombre de nouveautés depuis sa dernière visite — communiqués publiés et
 * transactions de la caisse (contributions et dons confirmés, entrées cash,
 * retraits). Ses propres actions ne comptent jamais.
 */
class GroupeBadgeService
{
    /**
     * @param \Illuminate\Support\Collection<int, Groupe> $groupes groupes du membre, pivot chargé
     * @return array<int, int> groupeId => nombre de nouveautés
     */
    public function compter(User $user, $groupes): array
    {
        $comptes = [];

        foreach ($groupes as $groupe) {
            // Dernière visite, à défaut la date d'entrée dans le groupe.
            $depuis = $groupe->pivot->lastReadAt ?? $groupe->pivot->created_at ?? now();

            $comptes[$groupe->id] =
                $groupe->communiques()
                    ->whereNull('archivedAt')
                    ->where('auteurId', '!=', $user->id)
                    ->where('created_at', '>', $depuis)
                    ->count()
                + Contribution::where('groupeCollecteId', $groupe->id)
                    ->where('statut', Contribution::STATUT_COMPLETED)
                    ->where('userId', '!=', $user->id)
                    ->where('created_at', '>', $depuis)
                    ->count()
                + GroupeEntreeCash::where('groupeId', $groupe->id)
                    ->whereNull('annuleAt')
                    ->where('auteurId', '!=', $user->id)
                    ->where('created_at', '>', $depuis)
                    ->count()
                + Retrait::where('groupeId', $groupe->id)
                    ->where('statut', '!=', Retrait::STATUT_FAILED)
                    ->where('userId', '!=', $user->id)
                    ->where('created_at', '>', $depuis)
                    ->count();
        }

        return $comptes;
    }

    /** Marque le groupe comme lu : les badges repartent de zéro. */
    public function marquerLu(User $user, Groupe $groupe): void
    {
        DB::table('groupe_user')
            ->where('groupeId', $groupe->id)
            ->where('userId', $user->id)
            ->update(['lastReadAt' => now()]);
    }
}
