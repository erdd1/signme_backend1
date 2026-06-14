<?php

namespace App\Modules\Payment\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Class PaymentResource
 * 
 * Resource pour formater les réponses de paiement
 */
class PaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'churchId' => $this->churchId,
            'amountPerMonth' => $this->amountPerMonth,
            'monthsCount' => $this->monthsCount,
            'totalAmount' => $this->totalAmount,
            'fees' => $this->fees,
            'amountCharged' => $this->amountCharged,
            'paymentOperator' => $this->paymentOperator,
            'paymentNumber' => $this->maskPhoneNumber($this->paymentNumber),
            'signatureType' => $this->signatureType,
            'status' => $this->status,
            'campayReference' => $this->campay_reference,
            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Masquer le numéro de téléphone pour la sécurité
     */
    private function maskPhoneNumber(string $phone): string
    {
        return substr($phone, 0, 5) . '****' . substr($phone, -2);
    }
}
