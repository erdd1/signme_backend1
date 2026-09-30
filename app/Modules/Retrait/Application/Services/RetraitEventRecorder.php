<?php

namespace App\Modules\Retrait\Application\Services;

use App\Models\RetraitEvent;

/**
 * Point d'écriture centralisé pour l'audit trail des retraits (retrait_events)
 * — même rôle que PaymentEventRecorder côté paiements entrants.
 */
class RetraitEventRecorder
{
    public static function recordEvent(
        int $retraitId,
        string $eventType,
        string $resultingStatus,
        ?array $rawPayload = null,
        ?int $triggeredByAdminId = null,
        ?string $sourceIp = null,
    ): RetraitEvent {
        return RetraitEvent::record([
            'retrait_id'            => $retraitId,
            'event_type'            => $eventType,
            'resulting_status'      => $resultingStatus,
            'raw_payload'           => $rawPayload,
            'triggered_by_admin_id' => $triggeredByAdminId,
            'source_ip'             => $sourceIp,
        ]);
    }
}
