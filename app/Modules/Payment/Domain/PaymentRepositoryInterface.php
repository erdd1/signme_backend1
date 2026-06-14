<?php

namespace App\Modules\Payment\Domain;

/**
 * Interface PaymentRepositoryInterface
 * 
 * Contrat pour l'accès aux données de paiement
 */
interface PaymentRepositoryInterface
{
    /**
     * Créer un paiement
     */
    public function create(array $data): PaymentEntity;

    /**
     * Récupérer un paiement par ID
     */
    public function findById($id): ?PaymentEntity;

    /**
     * Récupérer un paiement par référence Campay
     */
    public function findByCampayReference(string $reference): ?PaymentEntity;

    /**
     * Récupérer les paiements d'un utilisateur
     */
    public function findByUserId($userId, array $filters = []): array;

    /**
     * Mettre à jour le statut du paiement
     */
    public function updateStatus($paymentId, string $status, array $metadata = []): PaymentEntity;

    /**
     * Attacher des signatures au paiement
     */
    public function attachSignatures($paymentId, array $signatureIds): void;

    /**
     * Récupérer les signatures liées à un paiement
     */
    public function getSignaturesByPaymentId($paymentId): array;
}
