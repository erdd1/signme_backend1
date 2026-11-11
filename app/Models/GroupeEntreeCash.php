<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Argent reçu hors application (cash) par un groupe. Immuable : une erreur
 * s'annule (annuleAt + motif), elle ne se modifie ni ne se supprime.
 */
class GroupeEntreeCash extends Model
{
    protected $table = 'groupe_entrees_cash';

    protected $fillable = [
        'groupeId',
        'auteurId',
        'montant',
        'donateur',
        'note',
        'dateEntree',
        'annuleAt',
        'annuleParId',
        'motifAnnulation',
    ];

    protected function casts(): array
    {
        return [
            'montant'    => 'integer',
            'dateEntree' => 'date',
            'annuleAt'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupeId');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteurId');
    }

    public function estAnnulee(): bool
    {
        return $this->annuleAt !== null;
    }
}
