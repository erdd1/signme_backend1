<?php

namespace App\Modules\Evenement\Application\Services;

use App\Core\Exceptions\ApiException;
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
            $authorizationUrl = null;

            try {
                if ($paymentMethod === Contribution::METHOD_CARD) {
                    $result = $this->notchPayGateway->initiateCardPayment(
                        $contribution->id,
                        $feeData['total'],
                        $description,
                        $user->email,
                        (string) config('services.notchpay.callback_url'),
                    );
                    $paymentRef = $result['reference'];
                    $authorizationUrl = $result['authorizationUrl'];
                } else {
                    $paymentRef = $this->notchPayGateway->initiateMobileMoney(
                        $contribution->id,
                        $paymentNumber,
                        $paymentOperator,
                        $feeData['total'],
                        $description,
                        $user->email,
                    );
                }

                $contribution->update(['payment_reference' => $paymentRef]);

                PaymentEventRecorder::recordEvent(
                    null,
                    $contribution->id,
                    PaymentEvent::TYPE_SENT_TO_GATEWAY,
                    'pending',
                    ['payment_reference' => $paymentRef, 'paymentMethod' => $paymentMethod, 'authorization_url' => $authorizationUrl],
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

            $fresh = $contribution->fresh();
            $fresh->authorizationUrl = $authorizationUrl;

            return $fresh;
        });
    }

    /**
     * Récupère le statut d'une contribution (polling frontend).
     *
     * @throws ApiException
     */
    public function getStatus(User $user, string $contributionUuid): Contribution
    {
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
}
