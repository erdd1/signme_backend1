<?php

namespace App\Modules\Transaction\Application\Services;

use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\PaymentTransaction;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Service Transaction — « Mes transactions » : toutes les opérations
 * financières de l'utilisateur connecté, quelle que soit leur origine.
 *
 * Sources agrégées (lecture seule, aucune donnée dupliquée) :
 * - contributions aux événements et dons libres (Mobile Money ou carte)
 * - paiements de signature / Sainte-Cène à distance (Mobile Money ou carte)
 * - signatures en espèces remises à un ancien de service (une transaction
 *   par passage, tous les mois signés ensemble)
 *
 * Tout futur service financier s'ajoute ici en une méthode « fromXxx() ».
 * Le volume personnel est faible : chaque source est plafonnée aux
 * MAX_PAR_SOURCE lignes les plus récentes, puis filtrée et paginée en mémoire.
 */
class TransactionService
{
    public const TYPE_CONTRIBUTION = 'contribution';
    public const TYPE_DON          = 'don';
    public const TYPE_SIGNATURE    = 'signature';

    private const MAX_PAR_SOURCE = 500;

    private const MOIS = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    /**
     * @param array{q?: ?string, statut?: ?string, type?: ?string} $filters
     */
    public function list(User $user, array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $all = $this->contributions($user)
            ->concat($this->paiementsSignature($user))
            ->concat($this->signaturesEspeces($user))
            ->filter(fn (array $t) => $this->matches($t, $filters))
            ->sortByDesc('createdAt')
            ->values();

        return new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values()->all(),
            $all->count(),
            $perPage,
            $page,
        );
    }

    // ─── Sources ─────────────────────────────────────────────────────────────

    /** @return Collection<int, array<string, mixed>> */
    private function contributions(User $user): Collection
    {
        return Contribution::where('userId', $user->id)
            ->with('evenement.church')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PAR_SOURCE)
            ->get()
            ->map(function (Contribution $c) {
                $don = $c->evenement?->type === Evenement::TYPE_DON_LIBRE;
                $card = $c->payment_metadata['card'] ?? null;

                return $this->transaction(
                    id: "contribution:{$c->uuid}",
                    type: $don ? self::TYPE_DON : self::TYPE_CONTRIBUTION,
                    motif: $don ? 'Don à l\'église' : 'Contribution — ' . ($c->evenement?->titre ?? 'Événement'),
                    details: [],
                    eglise: $c->evenement?->church?->nom,
                    montant: $c->montant,
                    frais: $c->frais,
                    total: $c->montantTotal,
                    statut: $c->statut,
                    method: $c->paymentMethod === Contribution::METHOD_CARD ? 'card' : 'mobile_money',
                    operator: $c->paymentOperator,
                    number: $c->paymentNumber,
                    card: $card,
                    reference: $c->payment_reference,
                    createdAt: $c->created_at,
                );
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function paiementsSignature(User $user): Collection
    {
        return PaymentTransaction::where('userId', $user->id)
            ->with('church')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PAR_SOURCE)
            ->get()
            ->map(function (PaymentTransaction $t) {
                $mois = $this->libellesMois($this->decodeMois($t->months));

                return $this->transaction(
                    id: "signature:{$t->id}",
                    type: self::TYPE_SIGNATURE,
                    motif: $this->motifSignature($mois),
                    details: $mois,
                    eglise: $t->church?->nom,
                    montant: $t->totalAmount,
                    frais: $t->fees,
                    total: $t->amountCharged,
                    statut: $t->status,
                    method: $t->paymentMethod === PaymentTransaction::METHOD_CARD ? 'card' : 'mobile_money',
                    operator: $t->paymentOperator,
                    number: $t->paymentNumber,
                    card: $t->payment_metadata['card'] ?? null,
                    reference: $t->payment_reference,
                    createdAt: $t->created_at,
                );
            });
    }

    /**
     * Signatures en espèces : une ligne par mois en base, mais UNE transaction
     * par passage chez l'ancien (même ancien, même minute).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function signaturesEspeces(User $user): Collection
    {
        return Signature::where('userId', $user->id)
            ->where('paymentMethod', Signature::PAYMENT_ESPECES)
            ->with('church')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PAR_SOURCE)
            ->get()
            ->groupBy(fn (Signature $s) => $s->ancienId . '|' . $s->created_at?->format('Y-m-d H:i'))
            ->map(function (Collection $group) {
                /** @var Signature $first */
                $first = $group->sortBy('id')->first();
                $mois = $this->libellesMois($group->pluck('month')->sort()->values()->all());
                $montant = (int) $group->sum('amount');
                $frais = (int) $group->sum('fees');

                return $this->transaction(
                    id: "especes:{$first->id}",
                    type: self::TYPE_SIGNATURE,
                    motif: $this->motifSignature($mois),
                    details: $mois,
                    eglise: $first->church?->nom,
                    montant: $montant,
                    frais: $frais,
                    total: $montant + $frais,
                    statut: $first->status,
                    method: 'cash',
                    operator: null,
                    number: null,
                    card: null,
                    reference: null,
                    createdAt: $first->created_at,
                    receivedBy: $first->ancienNom,
                );
            })
            ->values();
    }

    // ─── Forme commune ───────────────────────────────────────────────────────

    /**
     * @param array<int, string>                         $details
     * @param array{last4?: string, brand?: ?string}|null $card
     * @return array<string, mixed>
     */
    private function transaction(
        string $id,
        string $type,
        string $motif,
        array $details,
        ?string $eglise,
        int $montant,
        int $frais,
        int $total,
        string $statut,
        string $method,
        ?string $operator,
        ?string $number,
        ?array $card,
        ?string $reference,
        $createdAt,
        ?string $receivedBy = null,
    ): array {
        return [
            'id'            => $id,
            'type'          => $type,
            'motif'         => $motif,
            'details'       => $details,
            'eglise'        => $eglise,
            'montant'       => $montant,
            'frais'         => $frais,
            'montantTotal'  => $total,
            'statut'        => $statut,
            'paymentMethod' => $method,
            'paymentLabel'  => $this->libelleMoyen($method, $operator),
            'paymentNumber' => $number,
            'cardBrand'     => $card['brand'] ?? null,
            'cardLast4'     => $card['last4'] ?? null,
            'reference'     => $reference,
            'receivedBy'    => $receivedBy,
            'createdAt'     => $createdAt?->toIso8601String(),
        ];
    }

    private function libelleMoyen(string $method, ?string $operator): string
    {
        return match (true) {
            $method === 'card'               => 'Carte bancaire',
            $method === 'cash'               => 'Espèces',
            $operator === 'ORANGE MONEY'     => 'Orange Money',
            $operator === 'MTN MOBILE MONEY' => 'MTN Mobile Money',
            default                          => 'Mobile Money',
        };
    }

    /** @param array<int, string> $mois libellés (« Janvier 2026 »…) */
    private function motifSignature(array $mois): string
    {
        return match (count($mois)) {
            0       => 'Sainte-Cène',
            1       => 'Sainte-Cène — ' . $mois[0],
            default => 'Sainte-Cène — ' . count($mois) . ' mois',
        };
    }

    /**
     * Les mois d'un paiement de signature sont stockés « YYYY-MM », parfois
     * encodés deux fois en JSON.
     *
     * @return array<int, string>
     */
    private function decodeMois(mixed $months): array
    {
        while (is_string($months)) {
            $decoded = json_decode($months, true);
            if (!is_array($decoded) && !is_string($decoded)) {
                break;
            }
            $months = $decoded;
        }

        return is_array($months) ? array_values(array_map('strval', $months)) : [];
    }

    /**
     * @param array<int, string> $mois « YYYY-MM »
     * @return array<int, string> « Janvier 2026 »
     */
    private function libellesMois(array $mois): array
    {
        return array_map(function (string $key) {
            [$year, $month] = array_pad(explode('-', $key), 2, null);
            $nom = self::MOIS[(int) $month] ?? null;

            return $nom ? "{$nom} {$year}" : $key;
        }, $mois);
    }

    // ─── Filtres ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $t
     * @param array{q?: ?string, statut?: ?string, type?: ?string} $filters
     */
    private function matches(array $t, array $filters): bool
    {
        if (!empty($filters['statut']) && $t['statut'] !== $filters['statut']) {
            return false;
        }
        if (!empty($filters['type']) && $t['type'] !== $filters['type']) {
            return false;
        }
        if (!empty($filters['q'])) {
            $needle = Str::of($filters['q'])->ascii()->lower()->trim()->toString();
            $haystack = Str::of(implode(' ', array_filter([
                $t['motif'], $t['eglise'], $t['paymentLabel'], $t['paymentNumber'],
                $t['cardLast4'], $t['cardBrand'], $t['reference'], $t['receivedBy'], ...$t['details'],
            ])))->ascii()->lower()->toString();

            return $needle === '' || str_contains($haystack, $needle);
        }

        return true;
    }
}
