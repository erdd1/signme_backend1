<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Retrait extends Model
{
    protected $table = 'retraits';

    public const STATUT_PENDING = 'pending';
    public const STATUT_PROCESSING = 'processing';
    public const STATUT_COMPLETED = 'completed';
    public const STATUT_FAILED = 'failed';

    protected $fillable = [
        'churchId',
        'userId',
        'retrait_beneficiaire_id',
        'montant_demande',
        'frais',
        'montant_net',
        'statut',
        'reference',
        'notchpay_transfer_id',
        'notchpay_status',
        'motif_echec',
        'solde_apres',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'montant_demande' => 'integer',
            'frais' => 'integer',
            'montant_net' => 'integer',
            'solde_apres' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function beneficiaire(): BelongsTo
    {
        return $this->belongsTo(RetraitBeneficiaire::class, 'retrait_beneficiaire_id');
    }

    public function isPending(): bool
    {
        return $this->statut === self::STATUT_PENDING;
    }

    public function isFinal(): bool
    {
        return in_array($this->statut, [self::STATUT_COMPLETED, self::STATUT_FAILED], true);
    }
}
