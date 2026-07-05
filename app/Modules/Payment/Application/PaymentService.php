<?php

namespace App\Modules\Payment\Application;

use App\Modules\Payment\Domain\PaymentEntity;
use App\Modules\Payment\Domain\PaymentRepositoryInterface;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;


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
        private NotchPayGateway $notchPayGateway,
    ) {}

    
    
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
