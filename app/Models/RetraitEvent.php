<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Historique MÉTIER, compact et immuable, des événements d'un retrait — même
 * conception que PaymentEvent (table INSERT-only, jamais mise à jour ni
 * supprimée). Utiliser exclusivement RetraitEvent::record().
 */
class RetraitEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_CREATED = 'created';
    public const TYPE_SENT_TO_GATEWAY = 'sent_to_gateway';
    public const TYPE_WEBHOOK = 'webhook_received';
    public const TYPE_CONFIRMED = 'confirmed';
    public const TYPE_FAILED = 'failed';
    public const TYPE_MANUAL_RECHECK = 'manual_recheck';

    protected $fillable = [
        'retrait_id',
        'event_type',
        'resulting_status',
        'raw_payload',
        'triggered_by_admin_id',
        'source_ip',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('RetraitEvent est immuable : aucune mise à jour autorisée.');
        });

        static::deleting(function () {
            throw new RuntimeException('RetraitEvent est immuable : aucune suppression autorisée.');
        });
    }

    public static function record(array $data): self
    {
        return static::create($data);
    }

    public function retrait(): BelongsTo
    {
        return $this->belongsTo(Retrait::class, 'retrait_id');
    }

    public function triggeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_admin_id');
    }
}
