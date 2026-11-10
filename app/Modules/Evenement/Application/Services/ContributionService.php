<?php

namespace App\Modules\Evenement\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Core\Helpers\DbCompat;
use App\Core\Services\PaymentQueueHealthService;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service Contribution — Contributions financières aux événements.
 *
 * Réutilise entièrement l'infrastructure de paiement existante (NotchPayGateway,
 * Redis Queue, webhook) via ContributionPollingDispatcher. Aucune duplication
 * de la logique NotchPay elle-même.
 *
 * Sécurité :
 * - L'événement doit être ACTIF au moment du lancement du paiement.
 * - Un seul paiement `pending` à la fois par utilisateur (tous événements confondus).
 * - Les frais sont toujours recalculés côté serveur (ContributionFeeCalculatorService).
 * - Idempotence webhook : verrouillage de ligne + vérification du statut avant écriture.
 * - Les montants financiers ne sont jamais modifiés après validation (`completed`).
 */
class ContributionService
{
    public function __construct(
        private readonly NotchPayGateway $notchPayGateway,
        private readonly ContributionPollingDispatcher $contributionPollingDispatcher,
        private readonly PaymentQueueHealthService $paymentQueueHealthService,
    ) {}

    /**
     * Initie une contribution à un événement (paiement NotchPay : mobile money ou carte).
     *
     * @throws ApiException
     */
    public function initiate(User $user, string $evenementUuid, array $data): Contribution
    {
        // ─── Vérifier que la file de paiement est joignable AVANT d'appeler
        // NotchPay : sinon l'utilisateur reçoit le PUSH de paiement mais le
        // serveur ne peut plus suivre sa confirmation (échec silencieux).
        $this->paymentQueueHealthService->ensureAvailable();

        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($evenementUuid)) {
            throw ApiException::notFound('Evenement');
        }

        $evenement = Evenement::where('uuid', $evenementUuid)->first();

        if (!$evenement) {
            throw ApiException::notFound('Evenement');
        }

        if ($evenement->statut !== Evenement::STATUT_ACTIF) {
            throw ApiException::forbidden(
                'Événement terminé. Vous ne pouvez plus effectuer une contribution.'
            );
        }

        $dejaEnCours = Contribution::where('userId', $user->id)
            ->where('statut', Contribution::STATUT_PENDING)
            ->exists();

        if ($dejaEnCours) {
            throw new ApiException(
                'Vous avez déjà un paiement en cours. Veuillez patienter avant d\'en lancer un nouveau.',
                409
            );
        }

        $montant = (int) $data['montant'];
        // Recalcule toujours les frais côté serveur : jamais confiance au frontend.
        $feeData = ContributionFeeCalculatorService::calculateTotal($montant);

        $note = isset($data['note']) ? mb_substr((string) $data['note'], 0, 100) : null;

        // Groupe du contributeur : dérivé automatiquement si l'utilisateur n'a
        // qu'un seul groupe, sélection obligatoire s'il en a plusieurs.
        $groupeId = $this->resolveGroupeId($user, isset($data['groupeId']) ? (int) $data['groupeId'] : null);

        $paymentMethod = $data['paymentMethod'];
        $paymentOperator = $data['paymentOperator'] ?? null;
        $paymentNumber = $data['paymentNumber'] ?? null;

        return DB::transaction(function () use ($user, $evenement, $montant, $feeData, $paymentMethod, $paymentOperator, $paymentNumber, $note, $groupeId) {
            $contribution = Contribution::create([
                'uuid'            => (string) Str::uuid(),
                'evenementId'     => $evenement->id,
                'userId'          => $user->id,
                'groupeId'        => $groupeId,
                'montant'         => $montant,
                'frais'           => $feeData['frais'],
                'montantTotal'    => $feeData['total'],
                'note'            => $note,
                'paymentOperator' => $paymentOperator,
                'paymentNumber'   => $paymentNumber,
                'paymentMethod'   => $paymentMethod,
                'statut'          => Contribution::STATUT_PENDING,
            ]);

            PaymentEventRecorder::recordEvent(
                null,
                $contribution->id,
                PaymentEvent::TYPE_CREATED,
                'pending',
                ['montant' => $montant, 'montantTotal' => $feeData['total']],
            );

            $description = "Contribution EEC - {$evenement->titre} - {$user->nom}";

            try {
                $paymentRef = $this->notchPayGateway->initiateMobileMoney(
                    $contribution->id,
                    $paymentNumber,
                    $paymentOperator,
                    $feeData['total'],
                    $description,
                    $user->email,
                );

                $contribution->update(['payment_reference' => $paymentRef]);

                PaymentEventRecorder::recordEvent(
                    null,
                    $contribution->id,
                    PaymentEvent::TYPE_SENT_TO_GATEWAY,
                    'pending',
                    ['payment_reference' => $paymentRef, 'paymentMethod' => $paymentMethod],
                );

                $this->contributionPollingDispatcher->schedule($contribution->id, $paymentRef);

                Log::info('Contribution événement — paiement NotchPay lancé', [
                    'contribution_id'   => $contribution->id,
                    'evenement_id'      => $evenement->id,
                    'payment_reference' => $paymentRef,
                    'paymentMethod'     => $paymentMethod,
                ]);
            } catch (\Throwable $e) {
                $contribution->update(['statut' => Contribution::STATUT_FAILED]);

                PaymentEventRecorder::recordEvent(
                    null,
                    $contribution->id,
                    PaymentEvent::TYPE_FAILED,
                    'failed',
                    ['error' => $e->getMessage(), 'stage' => 'initiation'],
                );

                Log::error('Contribution événement — échec initiation NotchPay', [
                    'contribution_id' => $contribution->id,
                    'error'           => $e->getMessage(),
                ]);

                throw $e instanceof ApiException
                    ? $e
                    : new ApiException('Erreur lors de l\'initiation du paiement.', 500);
            }

            return $contribution->fresh();
        });
    }

    /**
     * Récupère le statut d'une contribution (polling frontend).
     *
     * @throws ApiException
     */
    public function getStatus(User $user, string $contributionUuid): Contribution
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($contributionUuid)) {
            throw ApiException::notFound('Contribution');
        }

        $contribution = Contribution::where('uuid', $contributionUuid)
            ->where('userId', $user->id)
            ->first();

        if (!$contribution) {
            throw ApiException::notFound('Contribution');
        }

        return $contribution;
    }

    /**
     * Applique une notification NotchPay (webhook ou job de polling).
     * Ne valide pas le HMAC — réservé aux appels internes de confiance
     * (déjà validé par PaymentController avant la mise en file).
     */
    public function processPaymentNotification(array $payload): void
    {
        $paymentRef = $payload['reference'] ?? null;
        $rawStatus  = $payload['status']    ?? 'failed';

        if (!$paymentRef) {
            Log::warning('Contribution — notification NotchPay sans référence', $payload);
            return;
        }

        $internalStatus = $this->notchPayGateway->mapStatus($rawStatus);

        DB::transaction(function () use ($paymentRef, $internalStatus, $payload) {
            $contribution = Contribution::where('payment_reference', $paymentRef)
                ->lockForUpdate()
                ->first();

            if (!$contribution) {
                Log::warning('Contribution introuvable pour cette référence NotchPay', ['reference' => $paymentRef]);
                return;
            }

            // Idempotence : déjà traitée (webhook + polling peuvent arriver en double).
            if (in_array($contribution->statut, [Contribution::STATUT_COMPLETED, Contribution::STATUT_FAILED], true)) {
                Log::info('Contribution déjà traitée (idempotence)', ['contribution_id' => $contribution->id]);
                return;
            }

            $contribution->update([
                'statut'           => $internalStatus,
                'payment_metadata' => $payload,
                'confirmed_at'     => $internalStatus === Contribution::STATUT_COMPLETED ? now() : null,
            ]);

            if ($internalStatus === Contribution::STATUT_COMPLETED) {
                Evenement::where('id', $contribution->evenementId)->increment('montantCollecte', $contribution->montant);
                Evenement::where('id', $contribution->evenementId)->increment('nombreContributions');

                Log::info('Contribution complétée — événement crédité', [
                    'contribution_id' => $contribution->id,
                    'evenement_id'    => $contribution->evenementId,
                    'montant'         => $contribution->montant,
                ]);
            }
        });
    }

    /**
     * Récupère une contribution par uuid pour l'admin — aucune restriction,
     * cohérent avec le rôle `administrateur` qui voit tout dans le reste de
     * l'application.
     *
     * @throws ApiException
     */
    public function findByUuidForAdmin(string $uuid): Contribution
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Contribution');
        }

        $contribution = Contribution::where('uuid', $uuid)->first();

        if (!$contribution) {
            throw ApiException::notFound('Contribution');
        }

        return $contribution;
    }

    /**
     * Détermine le groupe du contributeur pour une contribution.
     *
     * - Aucun groupe : null (contribuer sans groupe reste possible).
     * - Un seul groupe : toujours dérivé automatiquement, jamais laissé au
     *   client (évite qu'un ID de groupe arbitraire soit envoyé).
     * - Plusieurs groupes : sélection obligatoire, doit être l'un des
     *   groupes de l'utilisateur.
     *
     * @throws ApiException
     */
    private function resolveGroupeId(User $user, ?int $requestedGroupeId): ?int
    {
        $groupeIds = $user->groupes()->pluck('groupes.id');

        if ($groupeIds->isEmpty()) {
            return null;
        }

        if ($groupeIds->count() === 1) {
            return $groupeIds->first();
        }

        if (!$requestedGroupeId || !$groupeIds->contains($requestedGroupeId)) {
            throw ApiException::badRequest(
                'Vous appartenez à plusieurs groupes. Veuillez sélectionner celui pour cette contribution.'
            );
        }

        return $requestedGroupeId;
    }

    /**
     * Liste les contributions d'un événement (vue trésorerie : pasteur/secrétaire/trésorier).
     */
    public function listForEvenement(User $authUser, string $evenementUuid, int $perPage = 20): LengthAwarePaginator
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($evenementUuid)) {
            throw ApiException::notFound('Evenement');
        }

        $evenement = Evenement::where('uuid', $evenementUuid)->first();

        if (!$evenement) {
            throw ApiException::notFound('Evenement');
        }

        if (!$authUser->isAdmin() && $evenement->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Accès refusé à cet événement.');
        }

        return Contribution::where('evenementId', $evenement->id)
            ->with(['user', 'evenement'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Liste toutes les contributions de l'église (onglet drawer trésorerie
     * mobile, et vue trésorerie admin — [$statut] permet à l'app mobile de
     * ne demander que les contributions "completed" ; laissé vide, l'admin
     * continue de voir tous les statuts pour l'audit/recheck).
     */
    public function listAllForTreasury(User $authUser, int $perPage = 20, ?string $statut = null): LengthAwarePaginator
    {
        $query = Contribution::query()->with(['user', 'evenement']);

        if (!$authUser->isAdmin()) {
            $query->whereHas('evenement', fn ($q) => $q->where('churchId', $authUser->churchId));
        }

        if (!empty($statut)) {
            $query->where('statut', $statut);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Liste filtrée des contributions "completed" de l'église (tableau de bord
     * contributions — vue mobile trésorerie).
     *
     * Filtres combinables :
     *   - event_id  : ID de l'événement
     *   - group_id  : ID du groupe du contributeur
     *   - year      : année de la contribution (created_at)
     *   - month     : mois de la contribution (created_at, 1-12)
     *
     * Toujours scopé à l'église de l'utilisateur authentifié (sauf admin).
     * Statut forcé à "completed" : les paiements en attente/échoués ne sont
     * pas exposés dans cette vue (réservé au panel admin pour audit).
     */
    public function listAllForTreasuryFiltered(
        User $authUser,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        return $this->buildTreasuryQuery($authUser, $filters)
            ->with(['user.groupes', 'evenement', 'groupe'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Variante non paginée de listAllForTreasuryFiltered — export PDF
     * (toutes les contributions correspondant aux filtres, sans limite).
     *
     * @return \Illuminate\Support\Collection<int, Contribution>
     */
    public function listAllForTreasuryExport(User $authUser, array $filters = []): \Illuminate\Support\Collection
    {
        return $this->buildTreasuryQuery($authUser, $filters)
            ->with(['user', 'evenement', 'groupe'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Requête de base partagée par listAllForTreasuryFiltered et
     * listAllForTreasuryExport — mêmes filtres, même scope église, même
     * statut "completed" (réservé au panel admin pour l'audit des autres
     * statuts).
     */
    private function buildTreasuryQuery(User $authUser, array $filters = [])
    {
        $query = Contribution::query()->where('statut', Contribution::STATUT_COMPLETED);

        if (!$authUser->isAdmin()) {
            $query->whereHas('evenement', fn ($q) => $q->where('churchId', $authUser->churchId));
        }

        if (!empty($filters['event_id'])) {
            $query->where('evenementId', (int) $filters['event_id']);
        }

        if (!empty($filters['group_id'])) {
            $query->where('groupeId', (int) $filters['group_id']);
        }

        if (!empty($filters['year'])) {
            $query->whereYear('created_at', (int) $filters['year']);
        }

        if (!empty($filters['month'])) {
            $query->whereMonth('created_at', (int) $filters['month']);
        }

        return $query;
    }

    /**
     * Calcule les statistiques financières agrégées des contributions "completed"
     * de l'église selon les mêmes filtres combinables que listAllForTreasuryFiltered.
     *
     * Retourne :
     *   - total_paye  : SUM(montantTotal) — ce que les contributeurs ont effectivement payé
     *   - total_frais : SUM(frais)        — frais prélevés sur les transactions
     *   - net_recu             : SUM(montant)      — montant net revenant à l'église
     *   - nombre_contributeurs : COUNT(DISTINCT userId) — contributeurs uniques
     *
     * Règle de comptage : un même utilisateur qui contribue N fois = 1 contributeur.
     * Si filtre groupe actif : compté 1 fois par groupe distinct (naturel via WHERE).
     * Formule vérifiable : total_paye = net_recu + total_frais.
     */
    public function getTreasuryStats(User $authUser, array $filters = []): array
    {
        $montantTotalCol = DbCompat::quoteColumn('montantTotal');
        $userIdCol = DbCompat::quoteColumn('userId');
        $result = $this->buildTreasuryQuery($authUser, $filters)->selectRaw(
            "COALESCE(SUM($montantTotalCol), 0)   as total_paye,
             COALESCE(SUM(frais), 0)           as total_frais,
             COALESCE(SUM(montant), 0)         as net_recu,
             COUNT(*)                           as count,
             COUNT(DISTINCT $userIdCol)             as nombre_contributeurs"
        )->first();

        return [
            'total_paye'           => (int) ($result->total_paye ?? 0),
            'total_frais'          => (int) ($result->total_frais ?? 0),
            'net_recu'             => (int) ($result->net_recu ?? 0),
            'count'                => (int) ($result->count ?? 0),
            'nombre_contributeurs' => (int) ($result->nombre_contributeurs ?? 0),
        ];
    }

    /**
     * Retourne les événements distincts ayant au moins une contribution
     * "completed" dans l'église de l'utilisateur authentifié.
     *
     * Utilisé pour alimenter le dropdown "Événement" des filtres du tableau
     * de bord — garantit que seuls les événements réellement contribués
     * apparaissent, indépendamment de la pagination de la liste.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, uuid: string, titre: string}>
     */
    public function getEvenementsAvecContributions(User $authUser): \Illuminate\Support\Collection
    {
        return Evenement::query()
            ->select(['evenements.id', 'evenements.uuid', 'evenements.titre'])
            ->whereHas('contributions', fn ($q) => $q->where('statut', Contribution::STATUT_COMPLETED))
            ->when(!$authUser->isAdmin(), fn ($q) => $q->where('evenements.churchId', $authUser->churchId))
            ->orderBy('evenements.titre')
            ->get()
            ->map(fn ($e) => [
                'id'    => $e->id,
                'uuid'  => $e->uuid,
                'titre' => $e->titre,
            ]);
    }
}
