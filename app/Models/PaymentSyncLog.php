<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle PaymentSyncLog — Diagnostic TECHNIQUE verbeux : une ligne par
 * ping de vérification automatique auprès du gateway (même si le statut
 * reste `pending`). Purgé après 7 jours par PurgeOldPaymentSyncLogsJob —
 * contrairement à PaymentEvent, la suppression par ce job est normale et
 * attendue, pas une anomalie.
 */
class PaymentSyncLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_transaction_id',
        'contribution_id',
        'checked_at',
        'resulting_status',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'checked_at'  => 'datetime',
        ];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class, 'contribution_id');
    }
}
