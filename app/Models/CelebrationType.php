<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle CelebrationType — Catalogue des 9 occasions gérées par le module
 * Célébrations. Contenu (titre/message) et activation modifiables par
 * l'admin ; les clés elles-mêmes sont fixes (voir CelebrationTypeSeeder).
 *
 * @property int         $id
 * @property string      $key
 * @property string      $label
 * @property string      $titre
 * @property string      $messageTemplate
 * @property string      $dateRuleType   'fixed'|'birthday'|'easter'
 * @property int|null    $fixedMonth
 * @property int|null    $fixedDay
 * @property string|null $genderFilter   'homme'|'femme'|null
 * @property string      $animationKey
 * @property bool        $active
 */
class CelebrationType extends Model
{
    public const KEY_ANNIVERSAIRE = 'anniversaire';
    public const KEY_NOUVEL_AN = 'nouvel_an';
    public const KEY_NOEL = 'noel';
    public const KEY_SAINT_VALENTIN = 'saint_valentin';
    public const KEY_JOURNEE_FEMME = 'journee_femme';
    public const KEY_FETE_TRAVAIL = 'fete_travail';
    public const KEY_PAQUES = 'paques';
    public const KEY_FETE_UNITE = 'fete_unite';
    public const KEY_FETE_JEUNESSE = 'fete_jeunesse';

    public const RULE_FIXED = 'fixed';
    public const RULE_BIRTHDAY = 'birthday';
    public const RULE_EASTER = 'easter';

    protected $fillable = [
        'key',
        'label',
        'titre',
        'messageTemplate',
        'dateRuleType',
        'fixedMonth',
        'fixedDay',
        'genderFilter',
        'animationKey',
        'active',
    ];

    protected $casts = [
        'fixedMonth' => 'integer',
        'fixedDay'   => 'integer',
        'active'     => 'boolean',
    ];

    public function celebrations(): HasMany
    {
        return $this->hasMany(Celebration::class);
    }

    public function isBirthday(): bool
    {
        return $this->dateRuleType === self::RULE_BIRTHDAY;
    }

    public function isEaster(): bool
    {
        return $this->dateRuleType === self::RULE_EASTER;
    }

    public function isFixed(): bool
    {
        return $this->dateRuleType === self::RULE_FIXED;
    }

    /**
     * Compose le message final en remplaçant {nom}, {eglise} et {annee} —
     * jamais stocké en dur, toujours calculé à la lecture : {nom}/{eglise}
     * à partir des données actuelles de l'utilisateur (reste exact même
     * après un changement de nom), {annee} à partir de l'année
     * d'occurrence réelle de la célébration (et non l'année de lecture,
     * pour qu'un historique consulté plus tard affiche toujours la bonne
     * année).
     */
    public function renderMessage(User $user, ?Church $church, ?int $year = null): string
    {
        return str_replace(
            ['{nom}', '{eglise}', '{annee}'],
            [$user->nom, $church?->nom ?? 'votre église', (string) ($year ?? now()->year)],
            $this->messageTemplate,
        );
    }
}
