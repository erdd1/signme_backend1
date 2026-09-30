<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle AboutChurchArticle — Module "À propos de mon Église"
 *
 * Tout contenu affiché sous le Card principal est un article typé :
 *   - pastor   : pasteur actuel ou ancien (voir statutPasteur)
 *   - activity : activité/projet majeur ayant marqué la communauté
 *   - group    : groupe de la communauté (conseillers, jeunesse…)
 *   - history  : élément d'histoire de la communauté
 *   - … extensible sans migration (voir TYPES et validation FormRequest)
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $churchId
 * @property string      $type
 * @property string      $titre
 * @property string      $contenu
 * @property array|null  $images         0 à 3 URLs d'images, ordre = affichage
 * @property string|null $statutPasteur  'actuel'|'ancien', uniquement si type=pastor
 * @property bool        $isPinned
 * @property \Illuminate\Support\Carbon|null $pinnedAt
 * @property int|null    $auteurId
 * @property string|null $auteurNom
 */
class AboutChurchArticle extends Model
{
    use HasFactory;

    public const TYPE_PASTOR   = 'pastor';
    public const TYPE_ACTIVITY = 'activity';
    public const TYPE_GROUP    = 'group';
    public const TYPE_HISTORY  = 'history';

    /** Whitelist applicative des types autorisés (validée en FormRequest). */
    public const TYPES = [
        self::TYPE_PASTOR,
        self::TYPE_ACTIVITY,
        self::TYPE_GROUP,
        self::TYPE_HISTORY,
    ];

    public const STATUT_PASTEUR_ACTUEL = 'actuel';
    public const STATUT_PASTEUR_ANCIEN = 'ancien';

    public const STATUTS_PASTEUR = [
        self::STATUT_PASTEUR_ACTUEL,
        self::STATUT_PASTEUR_ANCIEN,
    ];

    /** Nombre maximum de photos par article. */
    public const MAX_IMAGES = 3;

    /** Limite absolue de longueur du contenu (mots), décidée côté backend. */
    public const MAX_MOTS_CONTENU = 5000;

    /** Quotas d'épinglage par type, contrôlés côté service (pas en base). */
    public const MAX_PINNED_PASTORS    = 5;
    public const MAX_PINNED_GROUPS     = 5;
    public const MAX_PINNED_ACTIVITIES = 3;

    protected $fillable = [
        'uuid',
        'churchId',
        'type',
        'titre',
        'contenu',
        'images',
        'statutPasteur',
        'isPinned',
        'pinnedAt',
        'auteurId',
        'auteurNom',
    ];

    protected function casts(): array
    {
        return [
            'images'    => 'array',
            'isPinned'  => 'boolean',
            'pinnedAt'  => 'datetime',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteurId');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isPastor(): bool
    {
        return $this->type === self::TYPE_PASTOR;
    }

    public function isPasteurActuel(): bool
    {
        return $this->isPastor() && $this->statutPasteur === self::STATUT_PASTEUR_ACTUEL;
    }

    public function hasAtLeastOneImage(): bool
    {
        return !empty($this->images);
    }

    /** Quota maximum d'épinglés pour le type de cet article (null = non épinglable). */
    public static function maxPinnedForType(string $type): ?int
    {
        return match ($type) {
            self::TYPE_PASTOR   => self::MAX_PINNED_PASTORS,
            self::TYPE_GROUP    => self::MAX_PINNED_GROUPS,
            self::TYPE_ACTIVITY => self::MAX_PINNED_ACTIVITIES,
            default             => null,
        };
    }
}
