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
 * plusieurs groupes, avec un poste dans chacun : président, trésorier (les
 * deux cumulables) ou simple membre (aucun des deux).
 *
 * Un groupe ayant des mouvements d'argent ne se supprime plus : il est
 * archivé (`archivedAt`) et disparaît des listes, historique conservé.
 */
class Groupe extends Model
{
    use HasFactory;

    public const POSTE_PRESIDENT = 'president';
    public const POSTE_TRESORIER = 'tresorier';
    public const POSTES = [self::POSTE_PRESIDENT, self::POSTE_TRESORIER];

    protected $table = 'groupes';

    protected $fillable = [
        'uuid',
        'churchId',
        'nom',
        'createdBy',
        'archivedAt',
    ];

    protected function casts(): array
    {
        return ['archivedAt' => 'datetime'];
    }

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
        return $this->belongsToMany(User::class, 'groupe_user', 'groupeId', 'userId')
            ->withPivot(['estPresident', 'estTresorier', 'lastReadAt'])
            ->withTimestamps();
    }

    public function communiques(): HasMany
    {
        return $this->hasMany(GroupeCommunique::class, 'groupeId');
    }

    public function entreesCash(): HasMany
    {
        return $this->hasMany(GroupeEntreeCash::class, 'groupeId');
    }

    public function contributionsRecues(): HasMany
    {
        return $this->hasMany(Contribution::class, 'groupeCollecteId');
    }

    public function retraits(): HasMany
    {
        return $this->hasMany(Retrait::class, 'groupeId');
    }

    public function estArchive(): bool
    {
        return $this->archivedAt !== null;
    }
}
