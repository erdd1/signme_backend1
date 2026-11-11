<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Communiqué d'un groupe, publié par son président. Jamais supprimé : il
 * s'archive, ses contributions restent visibles dans l'historique.
 */
class GroupeCommunique extends Model
{
    protected $table = 'groupe_communiques';

    protected $fillable = [
        'groupeId',
        'auteurId',
        'titre',
        'message',
        'objectifMontant',
        'dateDebut',
        'dateFin',
        'archivedAt',
    ];

    protected function casts(): array
    {
        return [
            'objectifMontant' => 'integer',
            'dateDebut'       => 'date',
            'dateFin'         => 'date',
            'archivedAt'      => 'datetime',
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

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class, 'communiqueId');
    }

    public function entreesCash(): HasMany
    {
        return $this->hasMany(GroupeEntreeCash::class, 'communiqueId');
    }

    public function estArchive(): bool
    {
        return $this->archivedAt !== null;
    }
}
