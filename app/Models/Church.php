<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Church — Congrégation EEC
 *
 * @property int         $id
 * @property string      $nom
 * @property string      $ville
 * @property string|null $region
 * @property string|null $adresse
 * @property string|null $telephone
 * @property string|null $email
 * @property int|null    $pastorId
 * @property string|null $pastorNom
 */
class Church extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'ville',
        'region',
        'adresse',
        'telephone',
        'email',
        'pastorId',
        'pastorNom',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    public function pastor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pastorId');
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'churchId');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'churchId');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class, 'churchId');
    }

    public function ancienDesignations(): HasMany
    {
        return $this->hasMany(AncienDesignation::class, 'egliseId');
    }

    // ─── Scopes utiles ────────────────────────────────────────────────────────

    /**
     * Nombre de membres actifs dans l'église
     */
    public function getMembresCountAttribute(): int
    {
        return $this->members()->where('actif', true)->count();
    }
}
