<?php
namespace App\Modules\Tresorerie\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Core\Helpers\DbCompat;
use App\Models\Contribution;
use App\Models\Retrait;
use App\Models\Signature;
use App\Models\SortieFinanciere;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class SortieFinanciereService
{
    // =========================================================================
    // DASHBOARD — Stats globales + stats période
    // =========================================================================

    /**
     * Retourne :
     *  - Stats GLOBALES (toutes dates confondues) → solde actuel
     *  - Stats PÉRIODE  (filtrées par year/month) → informations de période
     *
     * Le solde actuel est toujours global même si un filtre période est actif.
     * L'API renvoie les deux blocs dans la même réponse.
     *
     * "Encaissé" regroupe DEUX sources d'argent entrant pour l'église :
     *  - les contributions aux événements (Contribution, statut completed)
     *  - les signatures de carte, présentiel ET à distance (Signature, status
     *    completed) — chacune apporte `amount` (offrande nette) + `fees`
     *    (frais de service, 0 en présentiel).
     * Ces deux sources sont scopées à l'église de l'utilisateur authentifié.
     */
    public function getDashboardStats(User $authUser, array $filters = []): array
    {
        $churchId = $authUser->churchId;

        // ── Contributions de base (completed, même église) ────────────────────
        $baseContrib = Contribution::query()
            ->where('statut', Contribution::STATUT_COMPLETED)
            ->whereHas('evenement', fn ($q) => $q->where('churchId', $churchId));

        // ── Signatures de base (completed, même église, présentiel + distance) ─
        $baseSignature = Signature::query()
            ->where('churchId', $churchId)
            ->where('status', Signature::STATUS_COMPLETED);

        // ── Sorties de base (même église) ─────────────────────────────────────
        $baseSortie = SortieFinanciere::query()->where('churchId', $churchId);

        // ── GLOBAL (toutes dates) ──────────────────────────────────────────────
        $globalContrib = (clone $baseContrib)->selectRaw(
            'COALESCE(SUM(' . DbCompat::quoteColumn('montantTotal') . '), 0) as total_encaisse,
             COALESCE(SUM(frais), 0)        as total_frais'
        )->first();

        $globalSignature = (clone $baseSignature)->selectRaw(
            'COALESCE(SUM(amount), 0) as total_montant,
             COALESCE(SUM(fees), 0)   as total_frais'
        )->first();

        $globalSimple = (clone $baseSortie)
            ->where('type', SortieFinanciere::TYPE_SIMPLE)
            ->where('statut', SortieFinanciere::STATUT_APPROVED)
            ->sum('montant');

        $globalFraisVal = (clone $baseSortie)
            ->where('type', SortieFinanciere::TYPE_FRAIS)
            ->where('statut', SortieFinanciere::STATUT_APPROVED)
            ->sum('montant');

        $globalFraisPending = (clone $baseSortie)
            ->where('type', SortieFinanciere::TYPE_FRAIS)
            ->where('statut', SortieFinanciere::STATUT_PENDING)
            ->sum('montant');

        // ── Retraits NotchPay (même église) — pending/processing/completed
        // sont TOUS déduits du solde disponible : un retrait en cours réserve
        // déjà les fonds, il ne doit pas pouvoir être doublé avant résolution.
        // Seul `failed` ne compte pas (fonds jamais réellement sortis).
        $totalRetraits = (int) Retrait::query()
            ->where('churchId', $churchId)
            ->whereIn('statut', [Retrait::STATUT_PENDING, Retrait::STATUT_PROCESSING, Retrait::STATUT_COMPLETED])
            ->sum('montant_demande');

        // ── Encaissé global = contributions (montant + frais) + signatures
        // (montant + frais) — deux sources combinées, même église uniquement.
        $totalEncaisseGlobal = (int) ($globalContrib->total_encaisse ?? 0)
            + (int) ($globalSignature->total_montant ?? 0)
            + (int) ($globalSignature->total_frais ?? 0);

        $totalFraisGeneres = (int) ($globalContrib->total_frais ?? 0)
            + (int) ($globalSignature->total_frais ?? 0);

        $totalSortiesSimples  = (int) $globalSimple;
        $totalSortieFraisVal  = (int) $globalFraisVal;
        $soldeActuel          = $totalEncaisseGlobal - $totalSortiesSimples - $totalSortieFraisVal - $totalRetraits;
        $fraisRestant         = $totalFraisGeneres - $totalSortieFraisVal;

        // ── PÉRIODE (filtres year / month) ─────────────────────────────────────
        $periodContrib   = clone $baseContrib;
        $periodSignature = clone $baseSignature;
        $periodSortie    = clone $baseSortie;

        if (!empty($filters['year'])) {
            $periodContrib->whereYear('contributions.created_at', (int) $filters['year']);
            $periodSignature->whereYear('signatures.created_at', (int) $filters['year']);
            $periodSortie->whereYear('sorties_financieres.created_at', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $periodContrib->whereMonth('contributions.created_at', (int) $filters['month']);
            $periodSignature->whereMonth('signatures.created_at', (int) $filters['month']);
            $periodSortie->whereMonth('sorties_financieres.created_at', (int) $filters['month']);
        }

        $periodStats = $periodContrib->selectRaw(
            'COALESCE(SUM(' . DbCompat::quoteColumn('montantTotal') . '), 0) as total_encaisse,
             COALESCE(SUM(frais), 0)        as total_frais'
        )->first();

        $periodSignatureStats = $periodSignature->selectRaw(
            'COALESCE(SUM(amount), 0) as total_montant,
             COALESCE(SUM(fees), 0)   as total_frais'
        )->first();

        $periodSimple = (clone $periodSortie)
            ->where('type', SortieFinanciere::TYPE_SIMPLE)
            ->where('statut', SortieFinanciere::STATUT_APPROVED)
            ->sum('montant');

        $periodFraisVal = (clone $periodSortie)
            ->where('type', SortieFinanciere::TYPE_FRAIS)
            ->where('statut', SortieFinanciere::STATUT_APPROVED)
            ->sum('montant');

        // ── Encaissé période = contributions + signatures de la période ────────
        $periodeEncaisse = (int) ($periodStats->total_encaisse ?? 0)
            + (int) ($periodSignatureStats->total_montant ?? 0)
            + (int) ($periodSignatureStats->total_frais ?? 0);

        $periodeFrais = (int) ($periodStats->total_frais ?? 0)
            + (int) ($periodSignatureStats->total_frais ?? 0);

        return [
            // ── Situation globale (toujours) ──────────────────────────────────
            'global' => [
                'total_encaisse'           => $totalEncaisseGlobal,
                'total_frais_generes'      => $totalFraisGeneres,
                'total_sorties_simples'    => $totalSortiesSimples,
                'total_sorties_frais_val'  => $totalSortieFraisVal,
                'sorties_frais_en_attente' => (int) $globalFraisPending,
                'total_retraits'           => $totalRetraits,
                'solde_actuel'             => $soldeActuel,
                'frais_restant_a_verser'   => $fraisRestant,
            ],
            // ── Stats de la période filtrée ───────────────────────────────────
            'periode' => [
                'total_encaisse'        => $periodeEncaisse,
                'total_frais'           => $periodeFrais,
                'sorties_simples'       => (int) $periodSimple,
                'sorties_frais_val'     => (int) $periodFraisVal,
            ],
            'filters_applied' => [
                'year'  => $filters['year']  ?? null,
                'month' => $filters['month'] ?? null,
            ],
        ];
    }

    // =========================================================================
    // LISTE PAGINÉE
    // =========================================================================

    public function listSorties(User $authUser, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SortieFinanciere::query()
            ->with(['auteur:id,nom', 'validatedBy:id,nom'])
            ->where('churchId', $authUser->churchId)
            ->orderByDesc('created_at');

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }
        if (!empty($filters['year'])) {
            $query->whereYear('created_at', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $query->whereMonth('created_at', (int) $filters['month']);
        }

        return $query->paginate($perPage);
    }

    // =========================================================================
    // CRUD — Sortie simple
    // =========================================================================

    public function createSimple(User $authUser, array $data): SortieFinanciere
    {
        // Calcul du solde après cette sortie
        $stats  = $this->getDashboardStats($authUser);
        $solde  = $stats['global']['solde_actuel'] - (int) $data['montant'];

        return SortieFinanciere::create([
            'churchId'    => $authUser->churchId,
            'userId'      => $authUser->id,
            'type'        => SortieFinanciere::TYPE_SIMPLE,
            'statut'      => SortieFinanciere::STATUT_APPROVED, // immédiatement approuvée
            'montant'     => (int) $data['montant'],
            'description' => $data['description'],
            'reference'   => $data['reference'] ?? null,
            'solde_apres' => max(0, $solde),
        ]);
    }

    public function updateSimple(User $authUser, SortieFinanciere $sortie, array $data): SortieFinanciere
    {
        if (!$sortie->isEditableBy($authUser)) {
            throw ApiException::forbidden('Cette sortie ne peut pas être modifiée.');
        }

        $sortie->update([
            'montant'     => (int) ($data['montant']     ?? $sortie->montant),
            'description' => $data['description'] ?? $sortie->description,
            'reference'   => $data['reference']   ?? $sortie->reference,
        ]);

        // Recalcul du solde_apres
        $stats = $this->getDashboardStats($authUser);
        $sortie->update(['solde_apres' => max(0, $stats['global']['solde_actuel'])]);

        return $sortie->fresh(['auteur', 'validatedBy']);
    }

    // =========================================================================
    // CRUD — Sortie de frais
    // =========================================================================

    public function createFrais(User $authUser, array $data): SortieFinanciere
    {
        // Vérifie qu'on ne dépasse pas les frais disponibles
        $stats = $this->getDashboardStats($authUser);
        $fraisDisponibles = $stats['global']['frais_restant_a_verser'];

        if ((int) $data['montant'] > $fraisDisponibles) {
            throw ApiException::badRequest(
                "Le montant ({$data['montant']} FCFA) dépasse les frais disponibles ({$fraisDisponibles} FCFA)."
            );
        }

        return SortieFinanciere::create([
            'churchId'    => $authUser->churchId,
            'userId'      => $authUser->id,
            'type'        => SortieFinanciere::TYPE_FRAIS,
            'statut'      => SortieFinanciere::STATUT_PENDING, // en attente admin
            'montant'     => (int) $data['montant'],
            'description' => $data['description'],
            'reference'   => $data['reference'] ?? null,
            'solde_apres' => null, // calculé seulement à la validation
        ]);
    }

    // =========================================================================
    // SUPPRESSION (simple approved + frais pending)
    // =========================================================================

    public function delete(User $authUser, SortieFinanciere $sortie): void
    {
        if (!$sortie->isDeletableBy($authUser)) {
            throw ApiException::forbidden(
                'Cette sortie ne peut pas être supprimée (déjà validée, rejetée, ou vous n\'en êtes pas l\'auteur).'
            );
        }
        $sortie->delete();
    }

    // =========================================================================
    // ADMIN — Validation / Rejet
    // =========================================================================

    public function approve(User $admin, SortieFinanciere $sortie): SortieFinanciere
    {
        if (!$sortie->isPending() || !$sortie->isFrais()) {
            throw ApiException::badRequest(
                "Seule une sortie de frais en attente peut être validée (statut actuel: {$sortie->statut})."
            );
        }

        // Calcul du nouveau solde après validation
        $stats  = $this->getDashboardStats($sortie->auteur);
        $solde  = $stats['global']['solde_actuel'] - $sortie->montant;

        $sortie->update([
            'statut'       => SortieFinanciere::STATUT_APPROVED,
            'validated_by' => $admin->id,
            'validated_at' => now(),
            'solde_apres'  => max(0, $solde),
        ]);

        return $sortie->fresh(['auteur', 'validatedBy']);
    }

    public function reject(User $admin, SortieFinanciere $sortie, string $motif): SortieFinanciere
    {
        if (!$sortie->isPending() || !$sortie->isFrais()) {
            throw ApiException::badRequest(
                "Seule une sortie de frais en attente peut être rejetée (statut actuel: {$sortie->statut})."
            );
        }

        $sortie->update([
            'statut'       => SortieFinanciere::STATUT_REJECTED,
            'validated_by' => $admin->id,
            'validated_at' => now(),
            'motif_rejet'  => $motif,
            'solde_apres'  => null, // aucune déduction
        ]);

        return $sortie->fresh(['auteur', 'validatedBy']);
    }

    // =========================================================================
    // ADMIN — Liste globale
    // =========================================================================

    public function listAllForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SortieFinanciere::query()
            ->with(['auteur:id,nom', 'validatedBy:id,nom', 'church:id,nom'])
            ->orderByDesc('created_at');

        if (!empty($filters['church_id'])) {
            $query->where('churchId', (int) $filters['church_id']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }

        return $query->paginate($perPage);
    }

    /** Toutes les sorties de frais en attente (toutes églises). */
    public function listPending(int $perPage): LengthAwarePaginator
    {
        return SortieFinanciere::query()
            ->with(['auteur:id,nom', 'validatedBy:id,nom', 'church:id,nom'])
            ->where('type', SortieFinanciere::TYPE_FRAIS)
            ->where('statut', SortieFinanciere::STATUT_PENDING)
            ->orderBy('created_at') // plus ancien en premier (FIFO)
            ->paginate($perPage);
    }

    /** Trouve une sortie par UUID ou lève une exception 404. */
    public function findByUuid(string $uuid, ?User $authUser = null): SortieFinanciere
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée (contrairement à
        // MySQL, qui traite la colonne comme du texte et renvoie simplement
        // "aucune correspondance") — cette erreur marque toute la
        // transaction en cours comme invalide (y compris celle de
        // RefreshDatabase en tests), faisant échouer la moindre requête
        // suivante. Vérifier le format ici évite d'atteindre la base avec
        // une valeur invalide.
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Sortie financière');
        }

        $query = SortieFinanciere::with(['auteur:id,nom', 'validatedBy:id,nom'])
            ->where('uuid', $uuid);

        if ($authUser) {
            $query->where('churchId', $authUser->churchId);
        }

        $sortie = $query->first();

        if (!$sortie) {
            throw ApiException::notFound('Sortie financière');
        }

        return $sortie;
    }
}