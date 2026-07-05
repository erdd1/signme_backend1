<?php

namespace App\Modules\Payment\Application\Services;

use App\Models\PaymentEvent;
use App\Models\PaymentSyncLog;
use Illuminate\Support\Facades\Log;

/**
 * PaymentEventRecorder — Point d'écriture centralisé pour les deux tables
 * d'audit paiement (payment_events, payment_sync_logs), réutilisé par les
 * deux domaines (Signature/PaymentTransaction et Contribution).
 *
 * `recordEvent()` alimente l'historique métier permanent (payment_events).
 * `recordSyncLog()` alimente le diagnostic technique purgé après 7 jours
 * (payment_sync_logs) — appelé à CHAQUE ping de vérification automatique,
 * y compris ceux qui restent `pending`.
 */
class PaymentEventRecorder
{
    public static function recordEvent(
        ?int $paymentTransactionId,
        ?int $contributionId,
        string $eventType,
        string $resultingStatus,
        ?array $rawPayload = null,
        ?int $triggeredByAdminId = null,
        ?string $sourceIp = null,
    ): PaymentEvent {
        return PaymentEvent::record([
            'payment_transaction_id' => $paymentTransactionId,
            'contribution_id'        => $contributionId,
            'event_type'             => $eventType,
            'resulting_status'       => $resultingStatus,
            'raw_payload'            => $rawPayload,
            'triggered_by_admin_id'  => $triggeredByAdminId,
            'source_ip'              => $sourceIp,
        ]);
    }

    public static function recordSyncLog(
        ?int $paymentTransactionId,
        ?int $contributionId,
        string $resultingStatus,
        ?array $rawPayload = null,
    ): ?PaymentSyncLog {
        if (! $paymentTransactionId && ! $contributionId) {
            Log::warning('PaymentEventRecorder::recordSyncLog appelé sans aucune FK — ignoré.');
            return null;
        }

        return PaymentSyncLog::create([
            'payment_transaction_id' => $paymentTransactionId,
            'contribution_id'        => $contributionId,
            'checked_at'             => now(),
            'resulting_status'       => $resultingStatus,
            'raw_payload'            => $rawPayload,
        ]);
    }
}
