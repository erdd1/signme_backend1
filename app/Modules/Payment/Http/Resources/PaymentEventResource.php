<?php

namespace App\Modules\Payment\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PaymentEventResource — un événement de l'historique métier permanent
 * (payment_events). Réutilisée pour les deux domaines (Signature/Contribution).
 */
class PaymentEventResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'eventType'        => $this->event_type,
            'resultingStatus'  => $this->resulting_status,
            'rawPayload'       => $this->raw_payload,
            'triggeredByAdmin' => $this->triggeredByAdmin
                ? ['id' => $this->triggeredByAdmin->id, 'nom' => $this->triggeredByAdmin->nom]
                : null,
            'sourceIp'  => $this->source_ip,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
