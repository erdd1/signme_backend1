<?php

namespace App\Modules\Groupe\Application\Services;

use App\Models\Contribution;
use App\Models\Groupe;
use App\Models\GroupeEntreeCash;
use App\Models\Retrait;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Historique unifié de la caisse d'un groupe (entrées et sorties d'argent),
 * visible de tous les membres : contributions et dons en ligne, entrées cash
 * (annulées comprises, pour la transparence), retraits avec leur motif.
 *
 * Chaque source est plafonnée avant fusion (même approche que « Mes
 * transactions ») puis triée et paginée en mémoire.
 */
class GroupeMouvementsService
{
    private const MAX_PAR_SOURCE = 200;

    public const TYPE_CONTRIBUTION = 'contribution';
    public const TYPE_DON          = 'don';
    public const TYPE_CASH         = 'cash';
    public const TYPE_RETRAIT      = 'retrait';

    public function lister(Groupe $groupe, int $page, int $perPage): LengthAwarePaginator
    {
        $tous = $this->enLigne($groupe)
            ->concat($this->cash($groupe))
            ->concat($this->retraits($groupe))
            ->sortByDesc('date')
            ->values();

        return new LengthAwarePaginator(
            $tous->forPage($page, $perPage)->values(),
            $tous->count(),
            $perPage,
            $page,
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function enLigne(Groupe $groupe): Collection
    {
        return Contribution::where('groupeCollecteId', $groupe->id)
            ->where('statut', Contribution::STATUT_COMPLETED)
            ->with(['user:id,nom', 'communique:id,uuid,titre'])
            ->orderByDesc('created_at')
            ->limit(self::MAX_PAR_SOURCE)
            ->get()
            ->map(fn (Contribution $c) => [
                'id'            => "contribution:{$c->uuid}",
                'type'          => $c->communiqueId ? self::TYPE_CONTRIBUTION : self::TYPE_DON,
                'sens'          => 'entree',
                'montant'       => $c->montant,
                'acteur'        => $c->user?->nom,
                'libelle'       => $c->communique?->titre,
                'communiqueUuid' => $c->communique?->uuid,
                'note'          => $c->note,
                'annule'        => false,
                'date'          => $c->created_at?->toIso8601String(),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function cash(Groupe $groupe): Collection
    {
        return GroupeEntreeCash::where('groupeId', $groupe->id)
            ->with('auteur:id,nom')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PAR_SOURCE)
            ->get()
            ->map(fn (GroupeEntreeCash $e) => [
                'id'            => "cash:{$e->uuid}",
                'type'          => self::TYPE_CASH,
                'sens'          => 'entree',
                'montant'       => $e->montant,
                'acteur'        => $e->donateur ?: $e->auteur?->nom,
                'libelle'       => 'Reçu hors application',
                'communiqueUuid' => null,
                'note'          => $e->annuleAt ? "Annulé : {$e->motifAnnulation}" : $e->note,
                'annule'        => $e->estAnnulee(),
                'enregistrePar' => $e->auteur?->nom,
                'date'          => $e->created_at?->toIso8601String(),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function retraits(Groupe $groupe): Collection
    {
        return Retrait::where('groupeId', $groupe->id)
            ->where('statut', '!=', Retrait::STATUT_FAILED) // un échec n'a jamais sorti d'argent
            ->with('auteur:id,nom')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PAR_SOURCE)
            ->get()
            ->map(fn (Retrait $r) => [
                'id'            => "retrait:{$r->uuid}",
                'type'          => self::TYPE_RETRAIT,
                'sens'          => 'sortie',
                'montant'       => $r->montant_demande,
                'acteur'        => $r->auteur?->nom,
                'libelle'       => $r->motif,
                'communiqueUuid' => null,
                'note'          => null,
                'annule'        => false,
                'statut'        => $r->statut,
                'date'          => $r->created_at?->toIso8601String(),
            ]);
    }
}
