<?php

namespace App\Modules\Payment\Application;

use App\Modules\Payment\Application\DTOs\InitiatePaymentDTO;
use App\Modules\Payment\Application\DTOs\ProcessPaymentDTO;
use App\Modules\Payment\Domain\PaymentEntity;
use App\Modules\Payment\Domain\PaymentRepositoryInterface;
use App\Modules\Payment\Domain\PaymentCompleted;
use App\Modules\Payment\Domain\PaymentFailed;
use App\Modules\Payment\Infrastructure\ExternalServices\CampayGateway;
use App\Models\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Class PaymentService
 * 
 * Service métier pour les paiements
 * Orchestre la logique complexe et les interactions entre domaines
 */
class PaymentService
{
    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private CampayGateway $campayGateway,
    ) {}

    
    /**
     * Traiter le webhook de Campay
     * 
     * @throws Exception
     */
    public function processWebhook(ProcessPaymentDTO $dto): void
    {
        try {
            DB::transaction(function () use ($dto) {
                // 1️⃣ Récupérer le paiement
                $paymentEntity = $this->paymentRepository->findByCampayReference($dto->reference);

                if (!$paymentEntity) {
                    \Log::warning('Payment not found for reference: ' . $dto->reference);
                    return;
                }

                // 2️⃣ Vérifier que le paiement est en attente
                if (!$paymentEntity->isPending()) {
                    \Log::warning('Payment already processed: ' . $dto->reference);
                    return;
                }

                // 3️⃣ Traiter le statut Campay
                if ($dto->status === 'SUCCESSFUL') {
                    // Marquer le paiement comme complété
                    $paymentEntity->complete($dto->metadata);
                    $this->paymentRepository->updateStatus(
                        $paymentEntity->getId(),
                        'completed',
                        $dto->metadata
                    );

                    // Récupérer les signatures liées
                    $signatures = $this->paymentRepository->getSignaturesByPaymentId($paymentEntity->getId());

                    // Mettre à jour les signatures : pending → completed
                    Signature::whereIn('id', $signatures)->update(['status' => 'completed']);

                    // Déclencher l'événement de succès
                    Event::dispatch(new PaymentCompleted(
                        paymentId: $paymentEntity->getId(),
                        userId: $paymentEntity->getUserId(),
                        signatureIds: $signatures,
                        totalAmount: $paymentEntity->getTotalAmount()->getAmount(),
                    ));

                    \Log::info('Payment completed', [
                        'paymentId' => $paymentEntity->getId(),
                        'signatures' => $signatures,
                    ]);

                } elseif ($dto->status === 'FAILED') {
                    // Marquer le paiement comme échoué
                    $paymentEntity->fail($dto->metadata);
                    $this->paymentRepository->updateStatus(
                        $paymentEntity->getId(),
                        'failed',
                        $dto->metadata
                    );

                    // Mettre à jour les signatures : pending → failed
                    Signature::whereIn('id', $this->paymentRepository->getSignaturesByPaymentId($paymentEntity->getId()))
                        ->update(['status' => 'failed']);

                    // Déclencher l'événement d'échec
                    Event::dispatch(new PaymentFailed(
                        paymentId: $paymentEntity->getId(),
                        userId: $paymentEntity->getUserId(),
                        reason: $dto->metadata['reason'] ?? 'Payment failed',
                    ));

                    \Log::error('Payment failed', [
                        'paymentId' => $paymentEntity->getId(),
                        'reason' => $dto->metadata['reason'] ?? null,
                    ]);
                }
            });

        } catch (Exception $e) {
            \Log::error('Webhook processing error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Récupérer les paiements d'un utilisateur
     */
    public function getPaymentsByUser(int $userId, array $filters = []): array
    {
        return $this->paymentRepository->findByUserId($userId, $filters);
    }

    /**
     * Récupérer un paiement par ID
     */
    public function getPaymentById(int $paymentId): ?PaymentEntity
    {
        return $this->paymentRepository->findById($paymentId);
    }
}
