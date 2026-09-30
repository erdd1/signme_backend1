<?php

namespace App\Modules\Evenement\Application\Services;

use App\Models\Contribution;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Service MesContributions — Module "Mes dépenses"
 *
 * Historique et statistiques des DÉPENSES personnelles du seul utilisateur
 * connecté. Accessible à tous les rôles.
 *
 * Une "dépense" personnelle regroupe DEUX sources, chacune scopée au seul
 * utilisateur connecté et prise frais inclus (jamais séparés net/frais ici,
 * contrairement à la trésorerie d'église — cette vue est strictement
 * personnelle) :
 *  - les contributions aux événements (Contribution, statut completed,
 *    montantTotal = montant + frais)
 *  - les signatures de carte, présentiel ET à distance (Signature, status
 *    completed, amount + fees)
 */
class MesContributionsService
{
    /**
     * Historique personnel des contributions, filtré optionnellement par mois/année.
     *
     * Toutes les contributions de l'utilisateur remontent ici, quel que soit
     * leur statut (pending/completed/failed) : dès l'envoi d'une contribution
     * à distance, l'utilisateur doit la voir apparaître "en cours de
     * traitement" dans son historique, puis passer à "payé" ou "paiement
     * échoué" une fois le webhook de paiement traité — jamais disparaître ni
     * rester invisible entre-temps. Cette vue reste strictement scopée à
     * l'utilisateur connecté (`userId`) : seuls lui-même et la trésorerie
     * (pasteur/secrétaire/trésorier, via un autre endpoint) voient ces
     * statuts intermédiaires.
     */
    public function list(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $annee = isset($filters['annee']) ? (int) $filters['annee'] : null;

        $query = Contribution::where('userId', $user->id)
            ->with(['evenement.church', 'groupe'])
            ->orderByDesc('created_at');

        if ($annee) {
            $query->whereYear('created_at', $annee);
        }

        if (!empty($filters['mois'])) {
            $query->whereMonth('created_at', (int) $filters['mois']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Statistiques : total donné (à vie), ce mois, cette année, total sur la
     * période actuellement filtrée (mois/année sélectionnés dans l'écran),
     * et répartition à vie entre offrandes signatures et offrandes
     * événements. Toutes les sommes sont frais inclus.
     */
    public function stats(User $user, array $filters = []): array
    {
        $baseContrib = Contribution::where('userId', $user->id)
            ->where('statut', Contribution::STATUT_COMPLETED);

        $baseSignature = Signature::where('userId', $user->id)
            ->where('status', Signature::STATUS_COMPLETED);

        $anneeSelectionnee = isset($filters['annee']) ? (int) $filters['annee'] : now()->year;
        $moisSelectionne = !empty($filters['mois']) ? (int) $filters['mois'] : null;

        // ── Périodes filtrées (sélection utilisateur) ──────────────────────
        $periodeContrib = (clone $baseContrib)->whereYear('created_at', $anneeSelectionnee);
        $periodeSignature = (clone $baseSignature)->whereYear('created_at', $anneeSelectionnee);
        if ($moisSelectionne) {
            $periodeContrib->whereMonth('created_at', $moisSelectionne);
            $periodeSignature->whereMonth('created_at', $moisSelectionne);
        }

        // ── "Ce mois" / "Cette année" (toujours ancrés sur la date réelle) ──
        $ceMoisContrib = (clone $baseContrib)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month);
        $ceMoisSignature = (clone $baseSignature)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month);

        $cetteAnneeContrib = (clone $baseContrib)->whereYear('created_at', now()->year);
        $cetteAnneeSignature = (clone $baseSignature)->whereYear('created_at', now()->year);

        // ── Totaux à vie par source (pour la nouvelle ligne de la carte) ────
        $totalEvenements = (int) (clone $baseContrib)->sum('montantTotal');
        $totalSignatures = (int) $this->sumSignatureTotal(clone $baseSignature);

        return [
            'totalDonne' => $totalEvenements + $totalSignatures,
            'ceMois' => (int) $ceMoisContrib->sum('montantTotal')
                + $this->sumSignatureTotal($ceMoisSignature),
            'cetteAnnee' => (int) $cetteAnneeContrib->sum('montantTotal')
                + $this->sumSignatureTotal($cetteAnneeSignature),
            'totalEvenements' => $totalEvenements,
            'totalSignatures' => $totalSignatures,
            'periodeSelectionnee' => [
                'annee' => $anneeSelectionnee,
                'mois'  => $moisSelectionne,
                'total' => (int) $periodeContrib->sum('montantTotal')
                    + $this->sumSignatureTotal($periodeSignature),
            ],
        ];
    }

    /** Somme amount + fees d'une requête Signature (frais inclus, non séparés). */
    private function sumSignatureTotal(\Illuminate\Database\Eloquent\Builder $query): int
    {
        $row = $query->selectRaw(
            'COALESCE(SUM(amount), 0) as total_amount,
             COALESCE(SUM(fees), 0)   as total_fees'
        )->first();

        return (int) ($row->total_amount ?? 0) + (int) ($row->total_fees ?? 0);
    }
}