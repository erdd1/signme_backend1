<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Ville — Ville de résidence, gérée par le pasteur/la secrétaire.
 * Chaque ville est marquée "Au Cameroun" ou "Hors Cameroun" (estDiaspora)
 * au moment de sa création.
 */
class Ville extends Model
{
    use HasFactory;

    protected $table = 'villes';

    protected $fillable = [
        'uuid',
        'churchId',
        'nom',
        'estDiaspora',
        'createdBy',
    ];

    protected function casts(): array
    {
        return [
            'estDiaspora' => 'boolean',
        ];
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function residents(): HasMany
    {
        return $this->hasMany(User::class, 'villeResidenceId');
    }
}
