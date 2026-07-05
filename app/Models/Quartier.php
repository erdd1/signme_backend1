<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Quartier — Quartier de résidence, géré par le pasteur/la
 * secrétaire. Un utilisateur sélectionne son quartier dans cette liste.
 */
class Quartier extends Model
{
    use HasFactory;

    protected $table = 'quartiers';

    protected $fillable = [
        'uuid',
        'churchId',
        'nom',
        'createdBy',
    ];

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
        return $this->hasMany(User::class, 'quartierResidenceId');
    }
}
