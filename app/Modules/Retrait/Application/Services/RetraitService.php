<?php

namespace App\Modules\Retrait\Application\Services;

use App\Core\Exceptions\ApiException;
use App\Models\Retrait;
use App\Models\RetraitBeneficiaire;
use App\Models\RetraitEvent;
use App\Models\User;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Tresorerie\Application\Services\SortieFinanciereService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Retraits d'argent (pasteur/trésorier) vers Orange Money / MTN Mobile Money,
 * via NotchPay Transfer. Le solde disponible n'est JAMAIS interrogé auprès de
 * NotchPay (Balance::check() est globale à toute la plateforme, pas par
 * église) : il est recalculé côté serveur via SortieFinanciereService, seule
 * source de vérité — voir getDashboardStats().
 */
class RetraitService
{
    public function __construct(
        private SortieFinanciereService $sortieFinanciereService,
        private NotchPayGateway $notchPayGateway,
    ) {}

    /** Solde interne actuellement disponible pour l'église de l'utilisateur. */
    public function getSolde(User $authUser): int
    {
        $stats = $this->sortieFinanciereService->getDashboardStats($authUser);
        return (int) $stats['global']['solde_actuel'];
    }

    /** Compte de retrait actif de l'utilisateur, ou null si jamais enregistré. */
    public function getBeneficiaireActif(User $authUser): ?RetraitBeneficiaire
    {
        return RetraitBeneficiaire::query()
            ->where('userId', $authUser->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    /**
     * Enregistre (ou remplace) le compte de retrait de l'utilisateur.
     * L'ancien compte actif, s'il existe, est désactivé (jamais modifié —
     * historique conservé pour l'audit des retraits déjà effectués dessus).
     */
    public function registerBeneficiaire(User $authUser, string $operator, string $phone): RetraitBeneficiaire
    {
        $notchpayBeneficiary = $this->notchPayGateway->createBeneficiary($authUser->nom, $phone, $operator);
        $channel = $this->notchPayGateway->mapOperatorToChannel($operator);

        return DB::transaction(function () use ($authUser, $channel, $phone, $notchpayBeneficiary) {
            RetraitBeneficiaire::query()
                ->where('userId', $authUser->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return RetraitBeneficiaire::create([
                'userId'                  => $authUser->id,
                'churchId'                => $authUser->churchId,
                'channel'                 => $channel,
                'account_number'          => $phone,
                'name'                    => $authUser->nom,
                'notchpay_beneficiary_id' => $notchpayBeneficiary['id'],
                'is_active'               => true,
            ]);
        });
    }

    /**
     * Initie un retrait : valide le montant contre le solde interne (verrou
     * applicatif pour empêcher tout double-retrait concurrent), crée
     * l'enregistrement, puis déclenche le Transfer NotchPay.
     */
    public function initiateRetrait(User $authUser, int $montantDemande): Retrait
    {
        $montantMin = (int) config('payment.retrait.montant_min', 1000);
        if ($montantDemande < $montantMin) {
            throw ApiException::badRequest(
                "Le montant minimum de retrait est de {$montantMin} FCFA."
            );
        }

        $beneficiaire = $this->getBeneficiaireActif($authUser);
        if (!$beneficiaire) {
            throw ApiException::badRequest(
                "Aucun compte de retrait enregistré. Enregistrez d'abord votre numéro Orange Money/MTN Mobile Money."
            );
        }

        $calcul = RetraitFeeCalculatorService::calculate($montantDemande);

        // Verrou applicatif par église : empêche deux retraits simultanés de
        // se baser sur le même solde lu avant écriture (race condition).
        $lock = Cache::lock("retrait:church:{$authUser->churchId}", 10);

        $retrait = $lock->block(5, function () use ($authUser, $beneficiaire, $calcul) {
            $solde = $this->getSolde($authUser);
            if ($calcul['montant_demande'] > $solde) {
                throw ApiException::badRequest(
                    "Solde insuffisant. Solde disponible : {$solde} FCFA."
                );
            }

            $retrait = Retrait::create([
                'churchId'                => $authUser->churchId,
                'userId'                  => $authUser->id,
                'retrait_beneficiaire_id' => $beneficiaire->id,
                'montant_demande'         => $calcul['montant_demande'],
                'frais'                   => $calcul['frais'],
                'montant_net'             => $calcul['montant_net'],
                'statut'                  => Retrait::STATUT_PENDING,
                'reference'               => 'RETRAIT-' . $authUser->id . '-' . now()->timestamp . '-' . Str::random(6),
                'solde_apres'             => max(0, $solde - $calcul['montant_demande']),
            ]);

            RetraitEventRecorder::recordEvent(
                $retrait->id,
                RetraitEvent::TYPE_CREATED,
                Retrait::STATUT_PENDING,
                ['montant_demande' => $calcul['montant_demande'], 'frais' => $calcul['frais']],
            );

            return $retrait;
        });

        return $this->sendToGateway($retrait, $beneficiaire);
    }

    /**
     * Envoie le retrait à NotchPay. En cas d'échec immédiat (réseau, refus
     * API), le retrait est marqué `failed` — le solde interne le libère
     * automatiquement (getDashboardStats exclut les retraits `failed`).
     */
    private function sendToGateway(Retrait $retrait, RetraitBeneficiaire $beneficiaire): Retrait
    {
        try {
            $transfer = $this->notchPayGateway->initiateTransfer(
                $beneficiaire->notchpay_beneficiary_id,
                $retrait->montant_net,
                "Retrait — {$beneficiaire->church->nom}",
                $retrait->reference,
            );

            $mapped = $this->notchPayGateway->mapStatus($transfer['status'] ?? '');

            $retrait->update([
                'notchpay_transfer_id' => $transfer['id'] ?? null,
                'notchpay_status'      => $transfer['status'] ?? null,
                'statut'               => $mapped === Retrait::STATUT_COMPLETED
                    ? Retrait::STATUT_COMPLETED
                    : ($mapped === Retrait::STATUT_FAILED ? Retrait::STATUT_FAILED : Retrait::STATUT_PROCESSING),
                'completed_at'         => $mapped === Retrait::STATUT_COMPLETED ? now() : null,
            ]);

            RetraitEventRecorder::recordEvent(
                $retrait->id,
                RetraitEvent::TYPE_SENT_TO_GATEWAY,
                $retrait->fresh()->statut,
                $transfer,
            );
        } catch (Throwable $e) {
            $retrait->update([
                'statut'       => Retrait::STATUT_FAILED,
                'motif_echec'  => 'Échec de l\'envoi à NotchPay : ' . $e->getMessage(),
            ]);

            RetraitEventRecorder::recordEvent(
                $retrait->id,
                RetraitEvent::TYPE_FAILED,
                Retrait::STATUT_FAILED,
                ['error' => $e->getMessage()],
            );
        }

        return $retrait->fresh(['beneficiaire']);
    }

    /** Historique des retraits de l'église (visible pasteur + trésorier). */
    public function listHistorique(User $authUser, int $perPage): LengthAwarePaginator
    {
        return Retrait::query()
            ->with(['beneficiaire', 'auteur:id,nom'])
            ->where('churchId', $authUser->churchId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /** Trouve un retrait par UUID, scopé à l'église de l'utilisateur. */
    public function findByUuid(string $uuid, User $authUser): Retrait
    {
        // PostgreSQL valide le FORMAT d'un uuid avant même de comparer et
        // lève une erreur SQL pour une valeur mal formée, ce qui marque
        // toute la transaction en cours comme invalide — voir le
        // commentaire équivalent dans SortieFinanciereService::findByUuid().
        if (!Str::isUuid($uuid)) {
            throw ApiException::notFound('Retrait');
        }

        $retrait = Retrait::with(['beneficiaire', 'auteur:id,nom'])
            ->where('uuid', $uuid)
            ->where('churchId', $authUser->churchId)
            ->first();

        if (!$retrait) {
            throw ApiException::notFound('Retrait');
        }

        return $retrait;
    }

    /**
     * Revérifie le statut d'un retrait `pending`/`processing` directement
     * auprès de NotchPay — secours si le webhook `transfer.*` n'arrive pas.
     */
    public function recheck(Retrait $retrait): Retrait
    {
        if ($retrait->isFinal()) {
            throw ApiException::badRequest(
                "Ce retrait est déjà '{$retrait->statut}' — statut définitif, aucune revérification possible."
            );
        }

        $transfer = $this->notchPayGateway->verifyTransfer($retrait->reference);
        if ($transfer === null) {
            RetraitEventRecorder::recordEvent(
                $retrait->id,
                RetraitEvent::TYPE_MANUAL_RECHECK,
                $retrait->statut,
                ['message' => 'NotchPay indisponible', 'synthetic' => true],
            );
            throw ApiException::serviceUnavailable('NotchPay est indisponible pour le moment. Réessayez plus tard.');
        }

        $mapped = $this->notchPayGateway->mapStatus($transfer['status'] ?? '');

        $retrait->update([
            'notchpay_status' => $transfer['status'] ?? $retrait->notchpay_status,
            'statut'          => $mapped === Retrait::STATUT_COMPLETED
                ? Retrait::STATUT_COMPLETED
                : ($mapped === Retrait::STATUT_FAILED ? Retrait::STATUT_FAILED : Retrait::STATUT_PROCESSING),
            'completed_at'    => $mapped === Retrait::STATUT_COMPLETED ? now() : $retrait->completed_at,
        ]);

        RetraitEventRecorder::recordEvent(
            $retrait->id,
            \App\Models\RetraitEvent::TYPE_MANUAL_RECHECK,
            $retrait->fresh()->statut,
            $transfer,
        );

        return $retrait->fresh(['beneficiaire']);
    }

    /** Applique une notification de statut reçue par webhook (transfer.*). */
    public function applyWebhookStatus(Retrait $retrait, array $payload): void
    {
        if ($retrait->isFinal()) {
            return; // statut définitif déjà atteint — immutabilité financière
        }

        $mapped = $this->notchPayGateway->mapStatus($payload['status'] ?? '');

        $retrait->update([
            'notchpay_status' => $payload['status'] ?? $retrait->notchpay_status,
            'statut'          => $mapped === Retrait::STATUT_COMPLETED
                ? Retrait::STATUT_COMPLETED
                : ($mapped === Retrait::STATUT_FAILED ? Retrait::STATUT_FAILED : Retrait::STATUT_PROCESSING),
            'completed_at'    => $mapped === Retrait::STATUT_COMPLETED ? now() : $retrait->completed_at,
        ]);
    }
}
