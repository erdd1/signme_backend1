<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Modele Publication - Annonces et meditations de l'eglise
 */
class Publication extends Model
{
    use HasFactory, SoftDeletes;

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
            'deleted_at' => 'datetime',
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

    /**
     * Utilisateurs qui ont lu cette publication (via publication_reads)
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'publication_reads',
            'publicationId',
            'userId'
        )->withPivot('readAt');
    }
}