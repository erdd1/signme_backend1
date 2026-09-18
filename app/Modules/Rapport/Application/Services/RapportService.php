<?php

namespace App\Modules\Rapport\Application\Services;

use App\Models\Signature;
use App\Models\User;
use App\Core\Exceptions\ApiException;
use App\Core\Helpers\DbCompat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service Rapport — Rapports financiers et secrétariat EEC
 *
 * Module 6 : Rapport Secrétaire (qui a signé / pas signé)
 * Module 7 : Rapport Financier (mensuel, annuel, comparaison N vs N-1)
 */
class RapportService
{
    // =========================================================================
    // RAPPORT SECRÉTAIRE — Qui a signé pour un mois donné ?
    // =========================================================================

    /**
     * Rapport secrétaire : liste de tous les membres vs ceux qui ont signé.
     *
     * @param User   $authUser
     * @param string $monthKey   Format YYYY-MM
     * @param int|null $churchId (admin peut filtrer)
     * @return array
     * @throws ApiException
     */
    public function rapportSecretaire(User $authUser, string $monthKey, ?int $churchId = null): array
    {
        $egliseId = $this->resolveChurchId($authUser, $churchId);

        // Tous les fidèles actifs de l'église (tous rôles — ils signent tous
        // leur carte, quel que soit leur rôle).
        $tousLesFideles = User::where('churchId', $egliseId)
                       ->where('actif', true)
                       ->whereIn('role', ['paroissien', 'ancien', 'secretaire', 'tresorier', 'pasteur'])
                       ->orderBy('nom')
                       ->get(['id', 'nom', 'email', 'role', 'photoUrl', 'estCommuniant']);

        // Seuls les communiants participent à la sainte cène : un paroissien
        // non communiant est dissocié du suivi signé/non signé (il n'a
        // simplement pas de carte à signer).
        $membres = $tousLesFideles->where('estCommuniant', true)->values();

        // Signatures complétées pour ce mois
        $signesIds = Signature::where('churchId', $egliseId)
                              ->where('month', $monthKey)
                              ->where('status', 'completed')
                              ->pluck('userId')
                              ->toArray();

        // Construire la liste
        $signes    = [];
        $nonSignes = [];

        foreach ($membres as $membre) {
            $hasSigned = in_array($membre->id, $signesIds, true);
            $data = [
                'id'       => $membre->id,
                'nom'      => $membre->nom,
                'email'    => $membre->email,
                'role'     => $membre->role,
                'photoUrl' => $membre->photoUrl,
                'signed'   => $hasSigned,
            ];

            if ($hasSigned) {
                // Ajouter les détails de la signature
                $sig = Signature::where('userId', $membre->id)
                                ->where('month', $monthKey)
                                ->where('status', 'completed')
                                ->first();
                if ($sig) {
                    $data['signature'] = [
                        'amount'        => $sig->amount,
                        'fees'          => $sig->fees,
                        'signatureType' => $sig->signatureType,
                        'paymentMethod' => $sig->paymentMethod,
                    ];
                }
                $signes[] = $data;
            } else {
                $nonSignes[] = $data;
            }
        }

        $totalMontant = Signature::where('churchId', $egliseId)
                                 ->where('month', $monthKey)
                                 ->where('status', 'completed')
                                 ->sum('amount');

        return [
            'month'          => $monthKey,
            'eglise_id'      => $egliseId,
            'total_fideles'  => $tousLesFideles->count(),
            'total_membres'  => count($membres),
            'total_signes'   => count($signes),
            'total_non_signes' => count($nonSignes),
            'taux_signature' => count($membres) > 0
                ? round(count($signes) / count($membres) * 100, 1)
                : 0,
            'montant_collecte' => $totalMontant,
            'signes'           => $signes,
            'non_signes'       => $nonSignes,
        ];
    }

    // =========================================================================
    // RAPPORT FINANCIER — Vue mensuelle, annuelle, comparaison
    // =========================================================================

    /**
     * Rapport financier annuel avec détail mensuel.
     *
     * @param User     $authUser
     * @param int      $year
     * @param int|null $churchId
     * @return array
     */
    public function rapportFinancierAnnuel(User $authUser, int $year, ?int $churchId = null): array
    {
        $egliseId = $this->resolveChurchId($authUser, $churchId);
        $cacheKey = "rapport:finances:annuel:{$egliseId}:{$year}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($egliseId, $year) {
            return $this->buildRapportFinancierAnnuel($egliseId, $year);
        });
    }

    private function buildRapportFinancierAnnuel(int $egliseId, int $year): array
    {
        $moisNoms = [
            1  => 'Janvier',   2  => 'Février',   3  => 'Mars',
            4  => 'Avril',     5  => 'Mai',        6  => 'Juin',
            7  => 'Juillet',   8  => 'Août',       9  => 'Septembre',
            10 => 'Octobre',   11 => 'Novembre',   12 => 'Décembre',
        ];

        // Agrégation par mois pour l'année donnée (index churchId + year + monthIndex)
        $signatureTypeCol = DbCompat::quoteColumn('signatureType');
        $data = Signature::where('churchId', $egliseId)
                         ->where('year', $year)
                         ->where('status', 'completed')
                         ->select(
                             'monthIndex',
                             'month',
                             DB::raw('COUNT(*) as nb_signatures'),
                             DB::raw('SUM(amount) as total_offrandes'),
                             DB::raw('SUM(fees) as total_frais'),
                             DB::raw('SUM(amount + fees) as total_encaisse'),
                             DB::raw("SUM(CASE WHEN $signatureTypeCol = 'presentiel' THEN amount ELSE 0 END) as montant_presentiel"),
                             DB::raw("SUM(CASE WHEN $signatureTypeCol = 'distance' THEN amount ELSE 0 END) as montant_distance"),
                             DB::raw("COUNT(CASE WHEN $signatureTypeCol = 'presentiel' THEN 1 END) as nb_presentiel"),
                             DB::raw("COUNT(CASE WHEN $signatureTypeCol = 'distance' THEN 1 END) as nb_distance"),
                         )
                         ->groupBy('monthIndex', 'month')
                         ->orderBy('monthIndex')
                         ->get()
                         ->keyBy('monthIndex');

        // Construire les 12 mois avec 0 si pas de données
        $moisDetails = [];
        for ($m = 0; $m < 12; $m++) {
            $row = $data->get($m);
            $monthKey = $year . '-' . str_pad($m + 1, 2, '0', STR_PAD_LEFT);
            $moisDetails[] = [
                'monthIndex'         => $m,
                'monthName'          => $moisNoms[$m + 1],
                'month'              => $monthKey,
                'nb_signatures'      => $row?->nb_signatures      ?? 0,
                'total_offrandes'    => $row?->total_offrandes     ?? 0,
                'total_frais'        => $row?->total_frais         ?? 0,
                'total_encaisse'     => $row?->total_encaisse      ?? 0,
                'montant_presentiel' => $row?->montant_presentiel  ?? 0,
                'montant_distance'   => $row?->montant_distance    ?? 0,
                'nb_presentiel'      => $row?->nb_presentiel       ?? 0,
                'nb_distance'        => $row?->nb_distance         ?? 0,
            ];
        }

        // Totaux annuels
        // Totaux annuels — inclut désormais la répartition présentiel/distance,
// frais inclus (amount + fees), pour donner à l'église une vision exacte
// de ce qu'elle a perçu par canal de signature.
$annualTotals = Signature::where('churchId', $egliseId)
                         ->where('year', $year)
                         ->where('status', 'completed')
                         ->selectRaw(
                             "COUNT(*) as total_signatures,
                              SUM(amount) as total_offrandes,
                              SUM(fees) as total_frais,
                              SUM(CASE WHEN $signatureTypeCol = 'presentiel' THEN amount + fees ELSE 0 END) as total_presentiel,
                              SUM(CASE WHEN $signatureTypeCol = 'distance' THEN amount + fees ELSE 0 END) as total_distance"
                         )
                         ->first();

            return [
                        'year'           => $year,
                        'eglise_id'      => $egliseId,
                        'mois'           => $moisDetails,
                        'totaux_annuels' => [
                                                        'nb_signatures'    => $annualTotals?->total_signatures ?? 0,
                                                        'total_offrandes'  => $annualTotals?->total_offrandes  ?? 0,
                                                        'total_frais'      => $annualTotals?->total_frais      ?? 0,
                                                        'total_encaisse'   => ($annualTotals?->total_offrandes ?? 0) + ($annualTotals?->total_frais ?? 0),
                                                        'total_presentiel' => $annualTotals?->total_presentiel ?? 0,
                                                        'total_distance'   => $annualTotals?->total_distance   ?? 0,
    ],
];
    }

    /**
     * Rapport financier comparatif : N vs N-1
     *
     * @param User     $authUser
     * @param int|null $churchId
     * @return array
     */
    public function rapportComparaison(User $authUser, ?int $churchId = null): array
    {
        $egliseId = $this->resolveChurchId($authUser, $churchId);
        $currentYear = now()->year;
        $previousYear = $currentYear - 1;

        $getYearTotals = function (int $year) use ($egliseId): array {
            $result = Signature::where('churchId', $egliseId)
                               ->where('year', $year)
                               ->where('status', 'completed')
                               ->selectRaw('COUNT(*) as nb, SUM(amount) as total, SUM(fees) as frais')
                               ->first();
            return [
                'year'       => $year,
                'nb'         => $result?->nb    ?? 0,
                'total'      => $result?->total  ?? 0,
                'frais'      => $result?->frais  ?? 0,
                'encaisse'   => ($result?->total ?? 0) + ($result?->frais ?? 0),
            ];
        };

        $current  = $getYearTotals($currentYear);
        $previous = $getYearTotals($previousYear);

        // Calcul de l'évolution en %
        $evolution = $previous['total'] > 0
            ? round(($current['total'] - $previous['total']) / $previous['total'] * 100, 1)
            : ($current['total'] > 0 ? 100 : 0);

        return [
            'eglise_id'   => $egliseId,
            'current'     => $current,
            'previous'    => $previous,
            'evolution'   => [
                'pourcentage' => $evolution,
                'sens'        => $evolution > 0 ? 'hausse' : ($evolution < 0 ? 'baisse' : 'stable'),
                'difference'  => $current['total'] - $previous['total'],
            ],
        ];
    }

    /**
     * Rapport détail d'un mois spécifique.
     *
     * @param User     $authUser
     * @param string   $monthKey  YYYY-MM
     * @param int|null $churchId
     * @return array
     */
    public function rapportMois(User $authUser, string $monthKey, ?int $churchId = null): array
    {
        $egliseId = $this->resolveChurchId($authUser, $churchId);

        // Agrégats calculés en SQL (COUNT/SUM) — jamais charger chaque ligne
        // de signature en mémoire juste pour les compter/sommer. La liste
        // nominative des signataires n'est plus renvoyée ici : ce module
        // n'affiche que des totaux, la liste existe déjà dans "Rapport
        // secrétaire" (RapportSecretaireScreen), pas besoin de la dupliquer.
        $signatureTypeCol = DbCompat::quoteColumn('signatureType');
        $totals = Signature::where('churchId', $egliseId)
            ->where('month', $monthKey)
            ->where('status', 'completed')
            ->selectRaw(
                "COUNT(*) as nb_total,
                 SUM(CASE WHEN $signatureTypeCol = 'presentiel' THEN 1 ELSE 0 END) as nb_presentiel,
                 SUM(CASE WHEN $signatureTypeCol = 'distance' THEN 1 ELSE 0 END) as nb_distance,
                 COALESCE(SUM(amount), 0) as montant_total,
                 COALESCE(SUM(fees), 0) as frais_total,
                 COALESCE(SUM(amount + fees), 0) as encaisse_total"
            )
            ->first();

        return [
            'month'          => $monthKey,
            'eglise_id'      => $egliseId,
            'nb_total'       => (int) ($totals->nb_total ?? 0),
            'nb_presentiel'  => (int) ($totals->nb_presentiel ?? 0),
            'nb_distance'    => (int) ($totals->nb_distance ?? 0),
            'montant_total'  => (int) ($totals->montant_total ?? 0),
            'frais_total'    => (int) ($totals->frais_total ?? 0),
            'encaisse_total' => (int) ($totals->encaisse_total ?? 0),
        ];
    }

    // ─── Helper privé ─────────────────────────────────────────────────────────

    private function resolveChurchId(User $authUser, ?int $churchId): int
    {
        if ($authUser->isAdmin()) {
            $id = $churchId ?? $authUser->churchId;
            if (!$id) throw ApiException::badRequest('Veuillez spécifier une église (church_id).');
            return $id;
        }
        return $authUser->churchId;
    }
}
