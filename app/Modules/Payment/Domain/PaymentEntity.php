<?php

namespace App\Modules\Payment\Domain;

use App\Modules\Payment\Domain\ValueObjects\Money;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatus;
use Carbon\Carbon;

/**
 * Class PaymentEntity
 * 
 * Entité du domaine pour les paiements
 * Encapsule la logique métier de paiement (non-dépendant de Laravel/BD)
 */
class PaymentEntity
{
    private int $id;
    private int $userId;
    private int $churchId;
    private Money $amountPerMonth;
    private int $monthsCount;
    private Money $totalAmount;
    private Money $fees;
    private Money $amountCharged;
    private PaymentStatus $status;
    private string $paymentOperator;      // ORANGE MONEY, MTN MOBILE MONEY
    private string $paymentNumber;
    private string $signatureType;        // presentiel, distance
    private ?string $paymentReference;
    private array $paymentMetadata;
    private Carbon $createdAt;
    private ?Carbon $completedAt;

    public function __construct(
        int $id,
        int $userId,
        int $churchId,
        int $amountPerMonth,
        int $monthsCount,
        int $totalAmount,
        int $fees,
        int $amountCharged,
        string $paymentOperator,
        string $paymentNumber,
        string $signatureType,
        string $status = 'pending',
        ?string $paymentReference = null,
        array $paymentMetadata = [],
        ?Carbon $createdAt = null,
        ?Carbon $completedAt = null,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->churchId = $churchId;
        $this->amountPerMonth = new Money($amountPerMonth);
        $this->monthsCount = $monthsCount;
        $this->totalAmount = new Money($totalAmount);
        $this->fees = new Money($fees);
        $this->amountCharged = new Money($amountCharged);
        $this->status = new PaymentStatus($status);
        $this->paymentOperator = $paymentOperator;
        $this->paymentNumber = $paymentNumber;
        $this->signatureType = $signatureType;
        $this->paymentReference = $paymentReference;
        $this->paymentMetadata = $paymentMetadata;
        $this->createdAt = $createdAt ?? Carbon::now();
        $this->completedAt = $completedAt;
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getChurchId(): int
    {
        return $this->churchId;
    }

    public function getAmountPerMonth(): Money
    {
        return $this->amountPerMonth;
    }

    public function getMonthsCount(): int
    {
        return $this->monthsCount;
    }

    public function getTotalAmount(): Money
    {
        return $this->totalAmount;
    }

    public function getFees(): Money
    {
        return $this->fees;
    }

    public function getAmountCharged(): Money
    {
        return $this->amountCharged;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getPaymentOperator(): string
    {
        return $this->paymentOperator;
    }

    public function getPaymentNumber(): string
    {
        return $this->paymentNumber;
    }

    public function getSignatureType(): string
    {
        return $this->signatureType;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function getPaymentMetadata(): array
    {
        return $this->paymentMetadata;
    }

    public function getCreatedAt(): Carbon
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?Carbon
    {
        return $this->completedAt;
    }

    // ─── Logique métier ────────────────────────────────────────────────────────

    /**
     * Marquer le paiement comme complété
     */
    public function complete(array $paymentMetadata = []): void
    {
        if (!$this->status->isPending()) {
            throw new \DomainException('Cannot complete a payment that is not pending');
        }

        $newStatus = new PaymentStatus(PaymentStatus::COMPLETED);
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \DomainException('Cannot transition from ' . $this->status . ' to ' . $newStatus);
        }

        $this->status = $newStatus;
        $this->paymentMetadata = $paymentMetadata;
        $this->completedAt = Carbon::now();
    }

    /**
     * Marquer le paiement comme échoué
     */
    public function fail(array $paymentMetadata = []): void
    {
        if (!$this->status->isPending()) {
            throw new \DomainException('Cannot fail a payment that is not pending');
        }

        $newStatus = new PaymentStatus(PaymentStatus::FAILED);
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \DomainException('Cannot transition from ' . $this->status . ' to ' . $newStatus);
        }

        $this->status = $newStatus;
        $this->paymentMetadata = $paymentMetadata;
    }

    /**
     * Mettre à jour la référence de paiement
     */
    public function setPaymentReference(string $reference): void
    {
        $this->paymentReference = $reference;
    }

    /**
     * Vérifier si le paiement est payable
     */
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isCompleted(): bool
    {
        return $this->status->isCompleted();
    }

    public function isFailed(): bool
    {
        return $this->status->isFailed();
    }
}
