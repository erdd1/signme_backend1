<?php

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Domain\PaymentRepositoryInterface;
use App\Modules\Payment\Domain\PaymentEntity;
use App\Models\PaymentTransaction;
use Exception;

/**
 * Class PaymentRepository
 * 
 * Implémentation concrete du repository de paiement
 * Gère la persistance des paiements dans la BD
 */
class PaymentRepository implements PaymentRepositoryInterface
{
    /**
     * Créer un paiement
     */
    public function create(array $data): PaymentEntity
    {
        $model = PaymentTransaction::create($data);

        return $this->modelToEntity($model);
    }

    /**
     * Récupérer un paiement par ID
     */
    public function findById($id): ?PaymentEntity
    {
        $model = PaymentTransaction::find($id);

        return $model ? $this->modelToEntity($model) : null;
    }

    /**
     * Récupérer un paiement par référence de paiement
     */
    public function findByPaymentReference(string $reference): ?PaymentEntity
    {
        $model = PaymentTransaction::where('payment_reference', $reference)->first();

        return $model ? $this->modelToEntity($model) : null;
    }

    /**
     * Récupérer les paiements d'un utilisateur
     */
    public function findByUserId($userId, array $filters = []): array
    {
        $query = PaymentTransaction::where('userId', $userId);

        // Filtrer par statut si fourni
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtrer par église si fourni
        if (isset($filters['churchId'])) {
            $query->where('churchId', $filters['churchId']);
        }

        $models = $query->orderByDesc('created_at')->get();

        return $models->map(fn ($model) => $this->modelToEntity($model))->toArray();
    }

    /**
     * Mettre à jour le statut du paiement
     */
    public function updateStatus($paymentId, string $status, array $metadata = []): PaymentEntity
    {
        $model = PaymentTransaction::findOrFail($paymentId);

        $model->update([
            'status' => $status,
            'payment_metadata' => array_merge($model->payment_metadata ?? [], $metadata),
        ]);

        return $this->modelToEntity($model);
    }

    /**
     * Attacher des signatures au paiement
     */
    public function attachSignatures($paymentId, array $signatureIds): void
    {
        $model = PaymentTransaction::findOrFail($paymentId);

        $model->signatures()->attach($signatureIds);
    }

    /**
     * Récupérer les signatures liées à un paiement
     */
    public function getSignaturesByPaymentId($paymentId): array
    {
        $model = PaymentTransaction::findOrFail($paymentId);

        return $model->signatures()->pluck('signature_id')->toArray();
    }

    /**
     * Convertir un modèle Eloquent en entité de domaine
     */
    private function modelToEntity(PaymentTransaction $model): PaymentEntity
    {
        return new PaymentEntity(
            id: $model->id,
            userId: $model->userId,
            churchId: $model->churchId,
            amountPerMonth: $model->amountPerMonth,
            monthsCount: $model->monthsCount,
            totalAmount: $model->totalAmount,
            fees: $model->fees,
            amountCharged: $model->amountCharged,
            paymentOperator: $model->paymentOperator,
            paymentNumber: $model->paymentNumber,
            signatureType: $model->signatureType,
            status: $model->status,
            paymentReference: $model->payment_reference,
            paymentMetadata: $model->payment_metadata ?? [],
            createdAt: $model->created_at,
            completedAt: $model->updated_at && $model->status === 'completed' ? $model->updated_at : null,
        );
    }
}
