<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle Publication — Annonces et méditations de l'église
 */
class Publication extends Model
{
    use HasFactory;

    public const TYPE_ANNONCE   = 'annonce';
    public const TYPE_MEDITATION = 'meditation';

    protected $fillable = [
        'churchId',
        'type',
        'titre',
        'contenu',
        'referenceBiblique',
        'auteurId',
        'auteurNom',
        'auteurPhotoUrl',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
        ];
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteurId');
    }
}
