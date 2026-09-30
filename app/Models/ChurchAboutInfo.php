<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle ChurchAboutInfo — Infos permanentes du Card principal
 * ("À propos de mon Église")
 *
 * Relation 1-1 avec Church. Ne contient JAMAIS de contenu éditorial (ça,
 * c'est le rôle de AboutChurchArticle) — uniquement les champs saisis par
 * pasteur/secrétaire en complément des données déjà présentes sur Church
 * (nom, ville, adresse, région, récupérées automatiquement).
 *
 * @property int         $id
 * @property int         $churchId
 * @property string|null $district
 * @property string|null $horairesCulte
 * @property array|null  $activitesMajeures  Max 5 chaînes courtes (validé service)
 * @property int|null    $updatedBy
 */
class ChurchAboutInfo extends Model
{
    use HasFactory;

    /** Nombre maximum d'activités majeures récurrentes affichables. */
    public const MAX_ACTIVITES_MAJEURES = 5;

    /** Longueur maximale d'une activité majeure (doit tenir sur une ligne). */
    public const MAX_LONGUEUR_ACTIVITE = 80;

    protected $fillable = [
        'churchId',
        'district',
        'horairesCulte',
        'activitesMajeures',
        'updatedBy',
    ];

    protected function casts(): array
    {
        return [
            'activitesMajeures' => 'array',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updatedBy');
    }
}
