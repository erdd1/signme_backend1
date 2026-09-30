<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Compte de retrait (Orange Money / MTN Mobile Money) d'un pasteur/trésorier.
 * Voir migration create_retrait_beneficiaires_table pour la logique
 * d'immutabilité (jamais mis à jour, remplacé par un nouvel enregistrement).
 */
class RetraitBeneficiaire extends Model
{
    protected $table = 'retrait_beneficiaires';

    public const CHANNEL_ORANGE = 'cm.orange';
    public const CHANNEL_MTN = 'cm.mtn';

    protected $fillable = [
        'userId',
        'churchId',
        'channel',
        'account_number',
        'name',
        'notchpay_beneficiary_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    /** Nom lisible de l'opérateur, pour affichage. */
    public function operatorLabel(): string
    {
        return match ($this->channel) {
            self::CHANNEL_ORANGE => 'Orange Money',
            self::CHANNEL_MTN => 'MTN Mobile Money',
            default => $this->channel,
        };
    }

    /** Numéro masqué pour affichage (ex: 6XX XX 45 78), jamais le numéro complet en clair côté API. */
    public function maskedAccountNumber(): string
    {
        $number = (string) $this->account_number;
        $length = strlen($number);
        if ($length <= 4) {
            return $number;
        }

        return str_repeat('•', $length - 4) . substr($number, -4);
    }
}
