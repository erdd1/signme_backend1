<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Groupe — Groupe/ministère de l'église (ex. Chorale, Jeunesse),
 * géré par le pasteur/la secrétaire. Un utilisateur peut appartenir à
 * plusieurs groupes, dont un seul "principal" (users.groupePrincipalId).
 */
class Groupe extends Model
{
    use HasFactory;

    protected $table = 'groupes';

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

    public function membres(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'groupe_user', 'groupeId', 'userId');
    }

    public function membresPrincipal(): HasMany
    {
        return $this->hasMany(User::class, 'groupePrincipalId');
    }
}
