<?php

namespace App\Modules\Payment\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PaymentSyncLogResource — un ping de vérification automatique (diagnostic
 * technique, payment_sync_logs). Réutilisée pour les deux domaines.
 */
class PaymentSyncLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'checkedAt'       => $this->checked_at?->toISOString(),
            'resultingStatus' => $this->resulting_status,
            'rawPayload'      => $this->raw_payload,
            'createdAt'       => $this->created_at?->toISOString(),
        ];
    }
}
