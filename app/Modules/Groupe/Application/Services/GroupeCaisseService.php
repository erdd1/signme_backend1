<?php

namespace App\Modules\Groupe\Application\Services;

use App\Models\Contribution;
use App\Models\Groupe;
use App\Models\GroupeEntreeCash;
use App\Models\Retrait;

/**
 * Caisse d'un groupe — calculée à la lecture, jamais stockée, à partir de
 * trois sources strictement séparées :
 *
 *  - en ligne : contributions et dons complétés reçus PAR L'APPLICATION
 *    (montant net ; les frais de paiement restent à la charge du donateur) ;
 *  - cash     : argent reçu hors application, saisi par le président/trésorier ;
 *  - retraits : sorties vers Mobile Money (en cours + effectuées), débitées du
 *    seul solde en ligne — le cash n'est pas sur la plateforme.
 *
 * N'interroge JAMAIS les calculs de l'église : aucune fuite entre les deux.
 */
class GroupeCaisseService
{
    /** Statuts de retrait qui réservent déjà les fonds (comme pour l'église). */
    private const STATUTS_RETRAIT_RESERVES = [
        Retrait::STATUT_PENDING,
        Retrait::STATUT_PROCESSING,
        Retrait::STATUT_COMPLETED,
    ];

    /**
     * @return array{enLigne: int, retraits: int, soldeEnLigne: int, cash: int, total: int}
     */
    public function caisse(Groupe $groupe): array
    {
        $enLigne = (int) Contribution::where('groupeCollecteId', $groupe->id)
            ->where('statut', Contribution::STATUT_COMPLETED)
            ->sum('montant');

        $retraits = (int) Retrait::where('groupeId', $groupe->id)
            ->whereIn('statut', self::STATUTS_RETRAIT_RESERVES)
            ->sum('montant_demande');

        $cash = (int) GroupeEntreeCash::where('groupeId', $groupe->id)
            ->whereNull('annuleAt')
            ->sum('montant');

        $soldeEnLigne = $enLigne - $retraits;

        return [
            'enLigne'      => $enLigne,
            'retraits'     => $retraits,
            'soldeEnLigne' => $soldeEnLigne,
            'cash'         => $cash,
            'total'        => $soldeEnLigne + $cash,
        ];
    }

    /** Solde en ligne retirable (jamais négatif). */
    public function soldeRetirable(Groupe $groupe): int
    {
        return max(0, $this->caisse($groupe)['soldeEnLigne']);
    }

    /** Un groupe ayant le moindre mouvement d'argent ne se supprime plus. */
    public function aDesMouvements(Groupe $groupe): bool
    {
        return Contribution::where('groupeCollecteId', $groupe->id)->exists()
            || GroupeEntreeCash::where('groupeId', $groupe->id)->exists()
            || Retrait::where('groupeId', $groupe->id)->exists();
    }
}
