<?php

namespace App\Modules\Signature\Application\Services;

use App\Models\Signature;
use App\Models\User;
use App\Models\AncienDesignation;
use App\Models\PaymentEvent;
use App\Models\PaymentTransaction;
use App\Core\Helpers\FeeCalculator;
use App\Core\Exceptions\ApiException;
use App\Core\Services\PaymentQueueHealthService;
use App\Modules\AncienDesignation\Application\Services\AncienDesignationService;
use App\Modules\Payment\Application\Services\PaymentEventRecorder;
use App\Modules\Payment\Application\Services\PaymentPollingDispatcher;
use App\Modules\Payment\Application\Services\PaymentStatusCache;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Domain\Entities\AncienDesignationEntity;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service Signature — Module Signature
 *
 * Gère les 3 flux de signature :
 * 1. Signature à DISTANCE (paroissien + paiement NotchPay)
 * 2. Signature PRÉSENTIEL (ancien de service / pasteur / admin)
 * 3. Consultation (ma carte, historique, rapports)
 */
class SignatureService
{
    public function __construct(
        private readonly NotchPayGateway $notchPayGateway,
        private readonly AncienDesignationService $ancienDesignationService,
        private readonly PaymentPollingDispatcher $paymentPollingDispatcher,
        private readonly PaymentQueueHealthService $paymentQueueHealthService,
    ) {}

    // =========================================================================
    // FLUX 1 : Signature à Distance (Paroissien)
    // =========================================================================

    /**
     * Initie une signature à distance avec paiement NotchPay (Mobile Money
     * ou carte bancaire).
     *
     * Étapes :
     * 1. Valider les mois (non futurs, non déjà signés, pas de doublons)
     * 2. Calculer les frais selon la grille EEC
     * 3. Créer les signatures en statut 'pending'
     * 4. Initier le paiement NotchPay (PUSH Mobile Money, ou lien de paiement
     *    carte à ouvrir dans une WebView)
     * 5. Retourner la référence (et le lien carte le cas échéant) au Flutter
     *
     * @param User  $paroissien  Utilisateur connecté (paroissien)
     * @param array $data        Données validées : { months, amount, paymentMethod (mobile_money|card),
     *                           paymentOperator? (ORANGE MONEY|MTN MOBILE MONEY, requis si mobile_money),
     *                           phoneNumber? (requis si mobile_money) }
     * @return array              { payment_reference, total_amount, authorization_url? }
     * @throws ApiException
     */
    public function initiateDistanceSignature(User $paroissien, array $data): array
    {
        // ─── 0. Vérifier que la file de paiement est joignable AVANT d'appeler
        // NotchPay : sinon l'utilisateur reçoit le PUSH de paiement mais le
        // serveur ne peut plus suivre sa confirmation (échec silencieux).
        $this->paymentQueueHealthService->ensureAvailable();

        $months        = $data['months'];      // ['2026-01', '2026-02', ...]
        $amount        = (int) $data['amount'];
        $paymentMethod = $data['paymentMethod']; // mobile_money | card
        $paymentOperator = $data['paymentOperator'] ?? null; // ORANGE MONEY | MTN MOBILE MONEY (mobile_money uniquement)
        $phoneNumber   = $data['phoneNumber'] ?? null;

        Log::info("Initiation de signature à distance", [
            'userId'          => $paroissien->id,
            'userName'        => $paroissien->nom,
            'monthsCount'     => count($months),
            'months'          => $months,
            'amount'          => $amount,
            'paymentMethod'   => $paymentMethod,
            'paymentOperator' => $paymentOperator,
        ]);

        // ─── 1. Validation des mois ────────────────────────────────────────
        // Cette validation vérifie:
        // ✅ Pas de doublons
        // ✅ Format YYYY-MM correct
        // ✅ Pas de mois futur
        // ✅ Le mois n'est PAS déjà signé (status = 'completed')
        Log::info("Début de validation des mois");
        $this->validateMonths($paroissien->id, $months);
        Log::info("✅ Validation des mois réussie - Aucun doublon détecté");

        // ─── 2. Calcul des frais ───────────────────────────────────────────
        if (!FeeCalculator::isValidDistanceAmount($amount)) {
            throw ApiException::badRequest(
                "Montant invalide. Montants autorisés : " . implode(', ', FeeCalculator::ALLOWED_AMOUNTS) . " FCFA."
            );
        }
        $feeData = FeeCalculator::calculateTotal($amount, count($months));

        Log::info("✅ Montant validé - Frais calculés", [
            'amountPerMonth' => $amount,
            'totalFees'      => $feeData['fees'] * count($months),
            'totalCharged'   => $feeData['total'],
        ]);

        return DB::transaction(function () use ($paroissien, $months, $amount, $feeData, $paymentMethod, $paymentOperator, $phoneNumber) {

            // ─── 3. Créer un PaymentTransaction d'abord ───────────────────
            $transaction = PaymentTransaction::create([
                'userId'          => $paroissien->id,
                'churchId'        => $paroissien->churchId,
                'paymentOperator' => $paymentOperator,
                'paymentNumber'   => $phoneNumber,
                'paymentMethod'   => $paymentMethod,
                'amountPerMonth'  => $amount,
                'monthsCount'     => count($months),
                'totalAmount'     => $amount * count($months),
                'fees'            => $feeData['fees'] * count($months),
                'amountCharged'   => $feeData['total'],
                'status'          => 'pending',
                'signatureType'   => 'distance',
                'months'          => json_encode($months), // Stocker les mois pour le webhook
            ]);

            // ─── 3.5. Enregistrer le log (pending) ─────────────────────────
            \App\Models\SignatureLog::create([
                'userId'          => $paroissien->id,
                'churchId'        => $paroissien->churchId,
                'months'          => $months,
                'amountPerMonth'  => $amount,
                'totalAmount'     => $amount * count($months),
                'fees'            => $feeData['fees'] * count($months),
                'paymentMethod'   => $paymentOperator,
                'phoneNumber'     => $phoneNumber,
                'signatureType'   => 'distance',
                'status'          => 'pending',
                'transaction_id'  => $transaction->id,
            ]);

            Log::info("✅ Audit log créé - Paiement en attente", [
                'transaction_id' => $transaction->id,
                'status'         => 'pending',
                'months'         => $months,
            ]);

            PaymentEventRecorder::recordEvent(
                $transaction->id,
                null,
                PaymentEvent::TYPE_CREATED,
                'pending',
                ['months' => $months, 'amountCharged' => $feeData['total']],
            );

            // ─── 4. NE PAS créer les signatures en 'pending' ────────────────
            // Les signatures seront créées seulement quand le webhook NotchPay
            // confirme le paiement (status = 'completed')

            // ─── 5. Initier le paiement NotchPay ────────────────────────────
            $description = "Sainte Cène EEC - " . count($months) . " mois - {$paroissien->nom}";
            $authorizationUrl = null;

            Log::info("Initiation du paiement NotchPay", [
                'transaction_id' => $transaction->id,
                'paymentMethod'  => $paymentMethod,
                'amount'         => $feeData['total'],
                'description'    => $description,
            ]);

            try {
                if ($paymentMethod === PaymentTransaction::METHOD_CARD) {
                    $result = $this->notchPayGateway->initiateCardPayment(
                        $transaction->id,
                        $feeData['total'],
                        $description,
                        $paroissien->email,
                        (string) config('services.notchpay.callback_url'),
                    );
                    $paymentRef = $result['reference'];
                    $authorizationUrl = $result['authorizationUrl'];
                } else {
                    $paymentRef = $this->notchPayGateway->initiateMobileMoney(
                        $transaction->id,
                        $phoneNumber,
                        $paymentOperator,
                        $feeData['total'],
                        $description,
                        $paroissien->email,
                    );
                }

                $transaction->update(['payment_reference' => $paymentRef]);

                // Mettre à jour le log avec la référence NotchPay
                \App\Models\SignatureLog::where('transaction_id', $transaction->id)
                    ->update(['payment_reference' => $paymentRef]);

                PaymentEventRecorder::recordEvent(
                    $transaction->id,
                    null,
                    PaymentEvent::TYPE_SENT_TO_GATEWAY,
                    'pending',
                    ['payment_reference' => $paymentRef, 'paymentMethod' => $paymentMethod, 'authorization_url' => $authorizationUrl],
                );

                Log::info("✅ Paiement NotchPay lancé avec succès", [
                    'transaction_id'    => $transaction->id,
                    'payment_reference' => $paymentRef,
                    'status'            => 'pending',
                    'message'           => $paymentMethod === PaymentTransaction::METHOD_CARD
                        ? 'Lien de paiement carte généré.'
                        : 'Utilisateur doit confirmer sur son téléphone dans les 60s',
                ]);

                PaymentStatusCache::putFromTransaction($transaction->fresh());
                $this->paymentPollingDispatcher->schedule($transaction->id, $paymentRef);

            } catch (ApiException $e) {
                // En cas d'échec d'initiation, marquer tout en 'failed'
                $transaction->update(['status' => 'failed']);

                // Enregistrer le log (failed)
                \App\Models\SignatureLog::where('transaction_id', $transaction->id)
                    ->update([
                        'status' => 'failed',
                        'errorMessage' => $e->getMessage(),
                    ]);

                PaymentEventRecorder::recordEvent(
                    $transaction->id,
                    null,
                    PaymentEvent::TYPE_FAILED,
                    'failed',
                    ['error' => $e->getMessage(), 'stage' => 'initiation'],
                );

                Log::error("❌ Erreur lors de l'initiation du paiement NotchPay", [
                    'transaction_id' => $transaction->id,
                    'error'          => $e->getMessage(),
                    'code'           => $e->getCode(),
                ]);

                throw $e;
            }

            return [
                'payment_reference' => $paymentRef,
                'authorization_url' => $authorizationUrl,
                'total_amount'      => $feeData['total'],
                'amount_per_month'  => $amount,
                'fees_per_month'    => $feeData['fees'],
                'months_count'      => count($months),
                'months'            => $months,
                'status'            => 'pending',
                'message'           => $paymentMethod === PaymentTransaction::METHOD_CARD
                    ? 'Complétez votre paiement par carte.'
                    : 'Un PUSH de confirmation a été envoyé sur votre téléphone. Vous avez 60 secondes pour confirmer.',
                'transaction_id'    => $transaction->id,
            ];
        });
    }

    /**
     * Récupère l'état actuel d'une transaction de paiement.
     * Utilisé pour le polling frontend (mise à jour barre de progression).
     *
     * @param int $userId
     * @param int $transactionId
     * @return array { status, progress }
     * @throws ApiException
     */
    public function getTransactionStatus(int $userId, int $transactionId): array
    {
        $transaction = PaymentTransaction::where('id', $transactionId)
            ->where('userId', $userId)
            ->first();

        if (!$transaction) {
            throw ApiException::notFound('Transaction non trouvée ou non autorisée');
        }

        $cached = PaymentStatusCache::get($transactionId);
        if ($cached !== null) {
            return array_merge($cached, [
                'transaction_id' => $transactionId,
                'is_terminal'    => in_array($cached['status'] ?? '', ['completed', 'failed'], true),
                'poll_interval_ms' => ($cached['status'] ?? '') === 'pending' ? 3000 : 0,
            ]);
        }

        PaymentStatusCache::putFromTransaction($transaction);
        $payload = PaymentStatusCache::get($transactionId) ?? PaymentStatusCache::payloadFromModel($transaction);

        return array_merge($payload, [
            'transaction_id' => $transactionId,
            'is_terminal'    => in_array($transaction->status, ['completed', 'failed'], true),
            'poll_interval_ms' => $transaction->status === 'pending' ? 3000 : 0,
        ]);
    }

    // =========================================================================
    // FLUX 2 : Signature Présentielle (Ancien / Pasteur / Admin)
    // =========================================================================

    /**
     * Enregistre une signature présentielle (sans paiement mobile).
     *
     * Règles métier:
     * - Seuls un ancien de service, pasteur ou admin peut signer en présentiel
     * - Si ancien de service (non pasteur/admin): doit avoir une désignation pour ce mois
     * - Si ancien a des jours/horaires définis: ne peut signer QUE ces jours/heures
     * - Les anciens peuvent signer les mois passés (rattrapage)
     *
     * @param User  $signateur   Ancien de service, pasteur ou admin
     * @param array $data        { userId, months[]: YYYY-MM, amount }
     * @return array             Liste des signatures créées
     * @throws ApiException
     */
    public function signaturePresentielle(User $signateur, array $data): array
    {
        // Vérifier que le signataire a le droit de signer en présentiel
        if (!$signateur->isAdmin() && $signateur->role !== User::ROLE_PASTEUR && $signateur->role !== User::ROLE_ANCIEN) {
            throw ApiException::forbidden(
                'Seuls les anciens de service, pasteurs et admins peuvent signer en présentiel.'
            );
        }

        $paroissien = User::find($data['userId']);
        if (!$paroissien) throw ApiException::notFound('Paroissien');

        // Vérifier appartenance à la même église (sauf admin)
        if (!$signateur->isAdmin() && $paroissien->churchId !== $signateur->churchId) {
            throw ApiException::forbidden('Ce paroissien n\'appartient pas à votre église.');
        }

        $months = $data['months'];
        $amount = (int) $data['amount'];

        // Valider les mois (non déjà signés — pas de restriction sur les mois passés pour rattrapage)
        $this->validateMonths($paroissien->id, $months, allowFuture: false);

        // Si le signataire est un ancien (non pasteur/admin), appliquer les règles de disponibilité
        if ($signateur->role === User::ROLE_ANCIEN && !$signateur->isAdmin()) {
            $this->validerDisponibiliteAncien($signateur, $months);
        }

        return DB::transaction(function () use ($signateur, $paroissien, $months, $amount) {
            $signatures = [];

            foreach ($months as $monthKey) {
                [$year, $monthNum] = explode('-', $monthKey);

                $sig = Signature::create([
                    'userId'         => $paroissien->id,
                    'nomParoissien'  => $paroissien->nom,
                    'churchId'       => $paroissien->churchId,
                    'monthIndex'     => (int)$monthNum - 1,
                    'month'          => $monthKey,
                    'year'           => (int)$year,
                    'amount'         => $amount,
                    'fees'           => 0, // Pas de frais en présentiel
                    'paymentMethod'  => 'ESPECES',
                    'phoneNumber'    => null,
                    'signatureType'  => 'presentiel',
                    'status'         => 'completed',
                    'ancienId'       => $signateur->id,
                    'ancienNom'      => $signateur->nom,
                ]);

                $signatures[] = $sig;
            }

            return $signatures;
        });
    }

    /**
     * Valide que l'ancien a le droit de signer en présentiel pour les mois demandés.
     *
     * Règles:
     * - Pour le mois actuel: doit avoir une désignation ET respecter les jours/horaires
     * - Pour les mois passés: peut signer si a une QUELCONQUE désignation active ou expirée
     * - Pour les mois futurs: NON autorisé
     *
     * @param User $ancien
     * @param array $months ["YYYY-MM", ...]
     * @throws ApiException
     */
    private function validerDisponibiliteAncien(User $ancien, array $months): void
    {
        $maintenant = now();
        $moisActuel = $maintenant->month;
        $anneeActuelle = $maintenant->year;

        foreach ($months as $monthKey) {
            [$year, $monthNum] = explode('-', $monthKey);
            $year = (int)$year;
            $monthNum = (int)$monthNum;

            // Construire une date pour comparer
            $dateMonth = \Carbon\Carbon::createFromDate($year, $monthNum, 1);
            $endOfMonth = $dateMonth->copy()->endOfMonth();

            // Vérifier que ce n'est pas un mois futur
            if ($dateMonth->greaterThan($maintenant)) {
                throw ApiException::badRequest(
                    "Impossible de signer pour un mois futur ({$monthKey})."
                );
            }

            // Vérifier que l'ancien a une désignation pour ce mois
            // Pour les mois passés: peut avoir une désignation expirée aussi
            // Pour le mois actuel: doit avoir une désignation active (non expirée)
            $hasDesignation = AncienDesignation::where('ancienId', $ancien->id)
                                               ->where('egliseId', $ancien->churchId)
                                               ->where('mois', $monthNum)
                                               ->where('annee', $year)
                                               ->exists();

            if (!$hasDesignation) {
                throw ApiException::forbidden(
                    "Vous n'êtes pas désigné de service pour le mois de {$monthKey}. "
                    . "Seuls les anciens désignés peuvent signer en présentiel."
                );
            }

            // Si c'est le mois actuel, vérifier les jours/horaires
            if ($year === $anneeActuelle && $monthNum === $moisActuel) {
                $this->validerJoursHorairesAncien($ancien, $year, $monthNum);
            }
        }
    }

    /**
     * Valide que l'ancien respecte les jours/horaires de sa désignation pour ce mois.
     *
     * @param User $ancien
     * @param int $year
     * @param int $monthNum (1-12)
     * @throws ApiException
     */
    private function validerJoursHorairesAncien(User $ancien, int $year, int $monthNum): void
    {
        $designation = AncienDesignation::where('ancienId', $ancien->id)
                                        ->where('egliseId', $ancien->churchId)
                                        ->where('mois', $monthNum)
                                        ->where('annee', $year)
                                        ->first();

        if (!$designation) {
            return; // Déjà vérifié plus haut, mais par sécurité on sort
        }

        // Créer l'entity pour vérifier les disponibilités
        $entity = AncienDesignationEntity::fromData($designation->toArray());

        if (!$entity->peutSignerMaintenant()) {
            $dayOfWeek = now()->dayOfWeek === 0 ? 7 : now()->dayOfWeek;
            $heure = now()->format('H:i');

            $disponibilite = $this->formatDisponibiliteFromEntity($entity);

            throw ApiException::badRequest(
                "Vous ne pouvez pas signer à cette heure. {$disponibilite['resume']}"
            );
        }
    }

    /**
     * Formate la disponibilité à partir d'une entity pour l'affichage.
     */
    private function formatDisponibiliteFromEntity(AncienDesignationEntity $entity): array
    {
        $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

        $jours = $entity->getJours()->toArray();
        if ($jours === null) {
            $joursText = 'Tous les jours';
        } else {
            $joursNoms = \array_map(fn ($code) => $dayNames[$code - 1] ?? null, $jours);
            $joursNoms = \array_filter($joursNoms);
            $joursText = \implode(', ', $joursNoms);
        }

        $debut = $entity->getHoraires()->getDebut() ?? '00:00';
        $fin = $entity->getHoraires()->getFin() ?? '23:59';

        return [
            'jours' => $joursText,
            'horaires' => "{$debut} - {$fin}",
            'resume' => "Disponible: {$joursText} ({$debut} - {$fin})",
        ];
    }

    // =========================================================================
    // FLUX 3 : Webhook NotchPay — Confirmation du paiement
    // =========================================================================

    /**
     * Applique une notification NotchPay (webhook ou job Redis).
     * La validation HMAC est faite en amont par PaymentController::webhook().
     */
    public function processPaymentNotification(array $payload): void
    {
        $paymentRef = $payload['reference'] ?? null;
        $rawStatus  = $payload['status']    ?? 'failed';

        if (!$paymentRef) {
            Log::warning('NotchPay notification: Référence manquante', $payload);
            return;
        }

        $internalStatus = $this->notchPayGateway->mapStatus($rawStatus);

        Log::info('NotchPay notification reçue', [
            'reference'       => $paymentRef,
            'raw_status'      => $rawStatus,
            'internal_status' => $internalStatus,
        ]);

        DB::transaction(function () use ($paymentRef, $internalStatus, $payload) {

            // ─── Récupérer la PaymentTransaction ────────────────────────────
            $transaction = PaymentTransaction::where('payment_reference', $paymentRef)->first();

            if (!$transaction) {
                Log::warning("Webhook: PaymentTransaction non trouvée", [
                    'reference' => $paymentRef,
                    'payload'   => $payload,
                ]);
                return;
            }

            Log::info("PaymentTransaction trouvée", [
                'transaction_id'  => $transaction->id,
                'user_id'         => $transaction->userId,
                'months'          => $transaction->months,
                'status'          => $transaction->status,
            ]);

            // ─── Si paiement confirmé, créer les signatures ──────────────────
            if ($internalStatus === 'completed') {
                $months = json_decode($transaction->months, true) ?? [];
                
                if (empty($months)) {
                    Log::error("Webhook: Aucun mois trouvé pour créer les signatures", [
                        'reference'    => $paymentRef,
                        'months_raw'   => $transaction->months,
                        'transaction_id' => $transaction->id,
                    ]);
                } else {
                    Log::info("Création de signatures pour les mois", [
                        'reference'    => $paymentRef,
                        'months_count' => count($months),
                        'months'       => $months,
                    ]);

                    try {
                        foreach ($months as $monthKey) {
                            [$year, $monthNum] = explode('-', $monthKey);
                            
                            $user = User::find($transaction->userId);
                            $nomParoissien = $user?->nom ?? '';

                            // ────────────────────────────────────────────────────
                            // Vérifier si la signature existe DÉJÀ (n'importe quel statut)
                            // Car la contrainte UNIQUE(userId, month) s'applique à tous
                            // ────────────────────────────────────────────────────
                            $signature = Signature::where('userId', $transaction->userId)
                                                  ->where('month', $monthKey)
                                                  ->first();

                            if (!$signature) {
                                // Créer une NOUVELLE signature
                                Signature::create([
                                    'userId'         => $transaction->userId,
                                    'nomParoissien'  => $nomParoissien,
                                    'churchId'       => $transaction->churchId,
                                    'monthIndex'     => (int)$monthNum - 1,
                                    'month'          => $monthKey,
                                    'year'           => (int)$year,
                                    'amount'         => $transaction->amountPerMonth,
                                    'fees'           => $transaction->fees > 0 ? (int)($transaction->fees / $transaction->monthsCount) : 0,
                                    'paymentMethod'  => $transaction->paymentOperator,
                                    'phoneNumber'    => $transaction->paymentNumber,
                                    'signatureType'  => 'distance',
                                    'status'         => 'completed',
                                    'payment_reference' => $paymentRef,
                                ]);

                                Log::info("✅ Signature créée avec succès", [
                                    'user_id'  => $transaction->userId,
                                    'month'    => $monthKey,
                                    'status'   => 'completed',
                                    'reference' => $paymentRef,
                                ]);
                            } else {
                                // METTRE À JOUR la signature existante si status n'est pas 'completed'
                                if ($signature->status !== 'completed') {
                                    $signature->update([
                                        'status'             => 'completed',
                                        'payment_reference'   => $paymentRef,
                                        'amount'             => $transaction->amountPerMonth,
                                        'fees'               => $transaction->fees > 0 ? (int)($transaction->fees / $transaction->monthsCount) : 0,
                                        'paymentMethod'      => $transaction->paymentOperator,
                                        'phoneNumber'        => $transaction->paymentNumber,
                                    ]);

                                    Log::info("✅ Signature mise à jour (status: {$signature->status} → completed)", [
                                        'user_id'        => $transaction->userId,
                                        'month'          => $monthKey,
                                        'previous_status' => $signature->status,
                                        'reference'      => $paymentRef,
                                    ]);
                                } else {
                                    Log::warning("Signature existe déjà avec status='completed' (idempotence)", [
                                        'user_id'  => $transaction->userId,
                                        'month'    => $monthKey,
                                        'reference' => $paymentRef,
                                    ]);
                                }
                            }
                        }
                        
                        // ─── Enregistrer le log (completed) ──────────────────────
                        \App\Models\SignatureLog::create([
                            'userId'          => $transaction->userId,
                            'churchId'        => $transaction->churchId,
                            'months'          => $months,
                            'amountPerMonth'  => $transaction->amountPerMonth,
                            'totalAmount'     => $transaction->totalAmount,
                            'fees'            => $transaction->fees,
                            'paymentMethod'   => $transaction->paymentOperator,
                            'phoneNumber'     => $transaction->paymentNumber,
                            'signatureType'   => 'distance',
                            'status'          => 'completed',
                            'payment_reference' => $paymentRef,
                            'transaction_id'  => $transaction->id,
                        ]);
                        
                    } catch (\Exception $e) {
                        Log::error("Erreur lors de la création des signatures", [
                            'reference'    => $paymentRef,
                            'error'        => $e->getMessage(),
                            'trace'        => $e->getTraceAsString(),
                        ]);
                        
                        // ─── Enregistrer le log (failed) ─────────────────────────
                        \App\Models\SignatureLog::create([
                            'userId'          => $transaction->userId,
                            'churchId'        => $transaction->churchId,
                            'months'          => $months,
                            'amountPerMonth'  => $transaction->amountPerMonth,
                            'totalAmount'     => $transaction->totalAmount,
                            'fees'            => $transaction->fees,
                            'paymentMethod'   => $transaction->paymentOperator,
                            'phoneNumber'     => $transaction->paymentNumber,
                            'signatureType'   => 'distance',
                            'status'          => 'failed',
                            'payment_reference' => $paymentRef,
                            'transaction_id'  => $transaction->id,
                            'errorMessage'    => $e->getMessage(),
                        ]);
                        
                        throw $e;
                    }
                }
            } else {
                Log::info("Webhook: Statut non 'completed', pas de création de signatures", [
                    'reference'    => $paymentRef,
                    'status'       => $internalStatus,
                ]);
                
                // ─── Enregistrer le log (failed) si statut est failed ────────
                if ($internalStatus === 'failed') {
                    $months = json_decode($transaction->months, true) ?? [];
                    
                    \App\Models\SignatureLog::create([
                        'userId'          => $transaction->userId,
                        'churchId'        => $transaction->churchId,
                        'months'          => $months,
                        'amountPerMonth'  => $transaction->amountPerMonth,
                        'totalAmount'     => $transaction->totalAmount,
                        'fees'            => $transaction->fees,
                        'paymentMethod'   => $transaction->paymentOperator,
                        'phoneNumber'     => $transaction->paymentNumber,
                        'signatureType'   => 'distance',
                        'status'          => 'failed',
                        'payment_reference' => $paymentRef,
                        'transaction_id'  => $transaction->id,
                        'errorMessage'    => $payload['message'] ?? 'Paiement échoué',
                    ]);
                }
            }

            // ✅ Met à jour la PaymentTransaction — la clause `where('status', 'pending')`
            // garantit qu'un statut déjà terminal (completed/failed) ne peut
            // JAMAIS être écrasé par un événement tardif (webhook + revérification
            // manuelle arrivant en concurrence). `confirmed_at` n'est donc posé
            // qu'une seule fois, à la toute première transition vers 'completed'.
            $txnUpdated = PaymentTransaction::where('payment_reference', $paymentRef)
                                            ->where('status', 'pending')
                                            ->update([
                                                'status'          => $internalStatus,
                                                'payment_metadata' => json_encode($payload),
                                                'confirmed_at'    => $internalStatus === 'completed' ? now() : null,
                                                'updated_at'      => now(),
                                            ]);

            Log::info("PaymentTransaction mise à jour", [
                'reference'    => $paymentRef,
                'new_status'   => $internalStatus,
                'rows_updated' => $txnUpdated,
            ]);
        });

        $transaction = PaymentTransaction::where('payment_reference', $paymentRef)->first();
        if ($transaction) {
            PaymentStatusCache::putFromTransaction($transaction->fresh());
        }

        Log::info('✅ Notification NotchPay traitée', [
            'reference' => $paymentRef,
            'status' => $internalStatus,
        ]);
    }

    // =========================================================================
    // CONSULTATION : Ma Carte, Historique
    // =========================================================================

    /**
     * Retourne la carte d'un paroissien (toutes ses signatures par année).
     *
     * @param int $userId  ID du paroissien
     * @param int $year    Année (défaut : année en cours)
     * @return array
     */
    public function getMaCarte(int $userId, int $year): array
    {
        $cacheKey = "signatures:ma-carte:{$userId}:{$year}";

        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($userId, $year) {
            return $this->buildMaCarte($userId, $year);
        });
    }

    private function buildMaCarte(int $userId, int $year): array
    {
        $signatures = Signature::where('userId', $userId)
                               ->where('year', $year)
                               ->where('status', 'completed')
                               ->orderBy('monthIndex')
                               ->get();

        // Construire un tableau des 12 mois
        $moisNoms = [
            'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
            'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
        ];

        $carte = [];
        foreach ($moisNoms as $index => $nom) {
            $sig = $signatures->firstWhere('monthIndex', $index);
            $carte[] = [
                'monthIndex' => $index,
                'monthName'  => $nom,
                'month'      => $year . '-' . str_pad($index + 1, 2, '0', STR_PAD_LEFT),
                'signed'     => !is_null($sig),
                'signature'  => $sig ? [
                    'id'            => $sig->id,
                    'amount'        => $sig->amount,
                    'fees'          => $sig->fees,
                    'signatureType' => $sig->signatureType,
                    'paymentMethod' => $sig->paymentMethod,
                    'signed_at'     => $sig->created_at->format('Y-m-d H:i'),
                ] : null,
            ];
        }

        $total = $signatures->sum('amount');
        $currentMonth = now()->format('Y-m');
        $signedThisMonth = $signatures->where('month', $currentMonth)->count() > 0;

        return [
            'year'              => $year,
            'carte'             => $carte,
            'total_signe'       => $signatures->count(),
            'montant_total'     => $total,
            'signe_ce_mois'     => $signedThisMonth,
            'mois_disponibles'  => $this->getAvailableMonths($userId, $year),
        ];
    }

    /**
     * Retourne l'historique paginé des signatures (pasteur/admin).
     */
    public function getHistorique(User $authUser, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Signature::with(['user:id,nom,email,photoUrl', 'ancien:id,nom'])
                          ->orderByDesc('created_at');

        if (!$authUser->isAdmin()) {
            $query->where('churchId', $authUser->churchId);
        } elseif (!empty($filters['church_id'])) {
            $query->where('churchId', $filters['church_id']);
        }

        if (!empty($filters['month'])) {
            $query->where('month', $filters['month']);
        }
        if (!empty($filters['year'])) {
            $query->where('year', $filters['year']);
        }
        if (!empty($filters['type'])) {
            $query->where('signatureType', $filters['type']);
        }
        if (!empty($filters['search'])) {
            $query->where('nomParoissien', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    // =========================================================================
    // ADMIN CRUD : Gestion administrative des signatures
    // =========================================================================

    /**
     * Retourne une signature par son ID (admin / pasteur même église).
     *
     * @param int  $id
     * @param User $authUser
     * @return Signature
     * @throws ApiException
     */
    public function findById(int $id, User $authUser): Signature
    {
        $signature = Signature::with(['user:id,nom,email,photoUrl', 'ancien:id,nom'])->find($id);

        if (!$signature) {
            throw ApiException::notFound('Signature');
        }

        if (!$authUser->isAdmin() && $signature->churchId !== $authUser->churchId) {
            throw ApiException::forbidden('Vous ne pouvez pas accéder à cette signature.');
        }

        return $signature;
    }

    /**
     * Met à jour une signature (admin).
     * Champs modifiables : status, amount, fees.
     *
     * @param int   $id
     * @param array $data
     * @param User  $authUser
     * @return Signature
     * @throws ApiException
     */
    public function updateSignature(int $id, array $data, User $authUser): Signature
    {
        $signature = $this->findById($id, $authUser);

        $allowed = array_intersect_key($data, array_flip(['status', 'amount', 'fees']));
        $signature->update($allowed);

        Log::info('Signature mise à jour par admin', [
            'signature_id' => $id,
            'updated_by'   => $authUser->id,
            'changes'      => $allowed,
        ]);

        return $signature->fresh();
    }

    /**
     * Supprime une signature (admin).
     *
     * @param int  $id
     * @param User $authUser
     * @throws ApiException
     */
    public function deleteSignature(int $id, User $authUser): void
    {
        $signature = $this->findById($id, $authUser);
        $signature->delete();

        Log::info('Signature supprimée par admin', [
            'signature_id' => $id,
            'deleted_by'   => $authUser->id,
        ]);
    }

    /**
     * Retourne l'historique personnel des signatures à distance de l'utilisateur.
     * 
     * Affiche UNIQUEMENT les signatures à distance complétées de l'utilisateur connecté.
     * Chaque utilisateur voit son propre historique de paiements/signatures.
     * 
     * @param User  $authUser  Utilisateur connecté
     * @param array $filters   Filtres (year, month, status)
     * @param int   $perPage   Nombre par page
     * @return LengthAwarePaginator
     */
    public function getHistoriquePersonnel(User $authUser, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Signature::where('userId', $authUser->id)
                          ->where('signatureType', 'distance')
                          ->orderBy('created_at', 'desc');

        if (!empty($filters['year'])) {
            $query->where('year', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $query->where('month', $filters['month']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        Log::info('Historique personnel récupéré', [
            'userId'  => $authUser->id,
            'filters' => $filters,
        ]);

        return $query->paginate($perPage);
    }

    /**
     * Retourne l'historique des signatures en présentiel REÇUES par l'utilisateur.
     * 
     * L'utilisateur voit les signatures présentiel qu'il a reçues des anciens/pasteurs.
     * Avec le nom et rôle de celui qui a signé.
     * 
     * @param User  $authUser  Utilisateur connecté
     * @param array $filters   Filtres (year, month, status)
     * @param int   $perPage   Nombre par page
     * @return LengthAwarePaginator
     */
    public function getHistoriquePersonnelPresentiel(User $authUser, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Signature::where('userId', $authUser->id)
                          ->where('signatureType', 'presentiel')
                          ->with('ancien') // Charger le rôle du signataire
                          ->orderBy('created_at', 'desc');

        if (!empty($filters['year'])) {
            $query->where('year', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $query->where('month', $filters['month']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        Log::info('Historique personnel présentiel récupéré', [
            'userId'  => $authUser->id,
            'filters' => $filters,
        ]);

        return $query->paginate($perPage);
    }

    /**
     * Retourne l'historique des signatures en présentiel effectuées par l'utilisateur.
     * 
     * C'est la traçabilité des signatures que l'utilisateur (ancien/pasteur) a effectuées.
     * - Ancien: Voit UNIQUEMENT les signatures qu'il a effectuées
     * - Pasteur: Voit TOUTES les signatures présentiel de son église
     * 
     * @param User  $authUser  Utilisateur connecté (ancien ou pasteur)
     * @param array $filters   Filtres (year, month, status)
     * @param int   $perPage   Nombre par page
     * @return LengthAwarePaginator
     * @throws ApiException Si l'utilisateur n'a pas le rôle ancien/pasteur
     */
    public function getHistoriqueSignatures(User $authUser, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // Vérifier que l'utilisateur est ancien ou pasteur
        if (!$authUser->hasRole(['ancien', 'pasteur'])) {
            throw ApiException::forbidden(
                'Accès refusé. Seuls les anciens et pasteurs peuvent consulter cet historique.'
            );
        }

        $query = Signature::where('signatureType', 'presentiel')
                          ->orderBy('created_at', 'desc');

        // Ancien: Voir UNIQUEMENT ses propres signatures
        if ($authUser->hasRole('ancien')) {
            $query->where('ancienId', $authUser->id);
        } 
        // Pasteur: Voir les signatures présentiel de son église
        elseif ($authUser->hasRole('pasteur')) {
            $query->where('churchId', $authUser->churchId);
        }

        if (!empty($filters['year'])) {
            $query->where('year', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $query->where('month', $filters['month']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        Log::info('Historique des signatures récupéré', [
            'userId'     => $authUser->id,
            'userRole'   => $authUser->role,
            'filters'    => $filters,
        ]);

        return $query->paginate($perPage);
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    /**
     * Valide une liste de mois (format YYYY-MM).
     *
     * @throws ApiException si : mois futur, doublon en BDD, doublon dans la requête
     */
    private function validateMonths(int $userId, array $months, bool $allowFuture = false): void
    {
        $currentMonth = now()->format('Y-m');

        // Pas de doublons dans la requête elle-même
        if (count($months) !== count(array_unique($months))) {
            throw ApiException::badRequest('La liste des mois contient des doublons.');
        }

        foreach ($months as $monthKey) {
            // Format YYYY-MM
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthKey)) {
                throw ApiException::badRequest("Format de mois invalide : {$monthKey}. Utilisez YYYY-MM.");
            }

            // Mois non futur (sauf si explicitly autorisé)
            if (!$allowFuture && $monthKey > $currentMonth) {
                throw ApiException::badRequest(
                    "Impossible de signer pour un mois futur ({$monthKey})."
                );
            }

            // ─── VÉRIFICATION CRITIQUE: Le mois n'est PAS déjà signé ─────────
            // Vérifier que l'utilisateur n'a PAS DÉJÀ payé pour ce mois
            $existingSignature = Signature::where('userId', $userId)
                                          ->where('month', $monthKey)
                                          ->where('status', 'completed')
                                          ->first();

            if ($existingSignature) {
                Log::warning("Tentative de re-signature d'un mois déjà signé", [
                    'userId'         => $userId,
                    'monthKey'       => $monthKey,
                    'signature_id'   => $existingSignature->id,
                    'signature_date' => $existingSignature->created_at,
                ]);

                throw ApiException::conflict(
                    "Vous avez déjà signé votre carte pour le mois {$monthKey}. Vous ne pouvez pas signer deux fois le même mois."
                );
            }

            Log::info("Validation du mois réussie", [
                'userId'   => $userId,
                'monthKey' => $monthKey,
                'message'  => 'Mois non signé, paiement peut être initié',
            ]);
        }
    }

    /**
     * Retourne les mois disponibles (non encore signés) pour une année.
     */
    private function getAvailableMonths(int $userId, int $year): array
    {
        $signed = Signature::where('userId', $userId)
                           ->where('year', $year)
                           ->where('status', 'completed')
                           ->pluck('month')
                           ->toArray();

        $currentMonth = now()->format('Y-m');
        $available    = [];

        for ($m = 1; $m <= 12; $m++) {
            $monthKey = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
            if (!in_array($monthKey, $signed, true) && $monthKey <= $currentMonth) {
                $available[] = $monthKey;
            }
        }

        return $available;
    }
}
