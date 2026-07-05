<?php

namespace App\Modules\Payment\Http\Resources;

use App\Core\Helpers\PhoneMasker;
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
            'paymentNumber' => PhoneMasker::mask($this->paymentNumber),
            'paymentMethod' => $this->paymentMethod,
            'signatureType' => $this->signatureType,
            'status' => $this->status,
            'paymentReference' => $this->payment_reference,
            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
