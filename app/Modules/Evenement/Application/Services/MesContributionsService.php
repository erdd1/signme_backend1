<?php

namespace App\Modules\Evenement\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Service MesContributions — Module "Mes dépenses"
 *
 * Historique et statistiques des contributions du seul utilisateur connecté.
 * Accessible à tous les rôles.
 */
class MesContributionsService
{
    /**
     * Historique personnel des contributions, filtré optionnellement par mois/année.
     *
     * Seules les contributions `completed` (réussies) sont affichées ici :
     * l'utilisateur a déjà vu le résultat de son paiement (réussi, échoué ou
     * encore en attente) via l'écran de confirmation au moment même de la
     * tentative — ré-afficher les échecs et les paiements en attente dans
     * l'historique n'apporte rien et prête à confusion. Seule la vue
     * trésorerie (pasteur/secrétaire/trésorier) a besoin de voir ces statuts.
     *
     * @throws ApiException si l'année demandée est future
     */
    public function list(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $annee = isset($filters['annee']) ? (int) $filters['annee'] : null;
        $this->assertAnneeValide($annee);

        $query = Contribution::where('userId', $user->id)
            ->where('statut', Contribution::STATUT_COMPLETED)
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
     * Statistiques : total donné (à vie), ce mois, cette année, et total sur
     * la période actuellement filtrée (mois/année sélectionnés dans l'écran).
     * Seules les contributions `completed` comptent comme "données".
     */
    public function stats(User $user, array $filters = []): array
    {
        $base = Contribution::where('userId', $user->id)->where('statut', Contribution::STATUT_COMPLETED);

        $anneeSelectionnee = isset($filters['annee']) ? (int) $filters['annee'] : now()->year;
        $this->assertAnneeValide($anneeSelectionnee);
        $moisSelectionne = !empty($filters['mois']) ? (int) $filters['mois'] : null;

        $periodeQuery = (clone $base)->whereYear('created_at', $anneeSelectionnee);
        if ($moisSelectionne) {
            $periodeQuery->whereMonth('created_at', $moisSelectionne);
        }

        return [
            'totalDonne' => (int) (clone $base)->sum('montant'),
            'ceMois'     => (int) (clone $base)
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->sum('montant'),
            'cetteAnnee' => (int) (clone $base)
                ->whereYear('created_at', now()->year)
                ->sum('montant'),
            'periodeSelectionnee' => [
                'annee' => $anneeSelectionnee,
                'mois'  => $moisSelectionne,
                'total' => (int) $periodeQuery->sum('montant'),
            ],
        ];
    }

    /**
     * Impossible de sélectionner une année future (nous sommes en {now()->year}).
     */
    private function assertAnneeValide(?int $annee): void
    {
        if ($annee !== null && $annee > now()->year) {
            throw ApiException::badRequest('Impossible de sélectionner une année future.');
        }
    }
}
