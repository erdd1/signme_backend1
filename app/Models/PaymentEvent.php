<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Modèle PaymentEvent — Historique MÉTIER, compact et immuable, des
 * événements marquants d'un paiement (créé, envoyé au gateway, webhook
 * reçu, confirmé, échoué, expiré, revérifié manuellement par un admin).
 *
 * Table INSERT-only : jamais mise à jour ni supprimée une fois écrite
 * (argent réel — traçabilité permanente). Utiliser exclusivement
 * PaymentEvent::record() pour créer une ligne.
 */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_CREATED         = 'created';
    public const TYPE_SENT_TO_GATEWAY = 'sent_to_gateway';
    public const TYPE_WEBHOOK         = 'webhook_received';
    public const TYPE_CONFIRMED       = 'confirmed';
    public const TYPE_FAILED          = 'failed';
    public const TYPE_EXPIRED         = 'expired';
    public const TYPE_MANUAL_RECHECK  = 'manual_recheck';

    protected $fillable = [
        'payment_transaction_id',
        'contribution_id',
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
            throw new RuntimeException('PaymentEvent est immuable : aucune mise à jour autorisée.');
        });

        static::deleting(function () {
            throw new RuntimeException('PaymentEvent est immuable : aucune suppression autorisée.');
        });
    }

    /**
     * Point d'écriture unique. Exige exactement une des deux FK
     * (payment_transaction_id XOR contribution_id).
     */
    public static function record(array $data): self
    {
        $hasPayment = ! empty($data['payment_transaction_id']);
        $hasContribution = ! empty($data['contribution_id']);

        if ($hasPayment === $hasContribution) {
            throw new \InvalidArgumentException(
                'PaymentEvent::record() exige exactement une des deux FK (payment_transaction_id XOR contribution_id).'
            );
        }

        return static::create($data);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class, 'contribution_id');
    }

    public function triggeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_admin_id');
    }
}
