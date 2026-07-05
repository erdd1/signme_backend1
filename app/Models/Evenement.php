<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Evenement — Module collecte de fonds (crowdfunding interne église)
 *
 * Etats : brouillon -> planifie -> actif -> termine -> archive (-> desarchive vers actif)
 * Aucune suppression exposée (traçabilité légale/comptable).
 */
class Evenement extends Model
{
    use HasFactory;

    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_PLANIFIE  = 'planifie';
    public const STATUT_ACTIF     = 'actif';
    public const STATUT_TERMINE   = 'termine';
    public const STATUT_ARCHIVE   = 'archive';

    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_PLANIFIE,
        self::STATUT_ACTIF,
        self::STATUT_TERMINE,
        self::STATUT_ARCHIVE,
    ];

    /** Durée avant archivage automatique après la fin d'un événement */
    public const JOURS_AVANT_ARCHIVAGE = 3;

    protected $fillable = [
        'uuid',
        'churchId',
        'auteurId',
        'titre',
        'description',
        'imageUrl',
        'statut',
        'dateDebut',
        'dateFin',
        'publishedAt',
        'scheduledPublishAt',
        'termineAt',
        'archivedAt',
        'objectifMontant',
        'montantCollecte',
        'nombreContributions',
    ];

    protected function casts(): array
    {
        return [
            'dateDebut'          => 'date',
            'dateFin'            => 'datetime',
            'publishedAt'        => 'datetime',
            'scheduledPublishAt' => 'datetime',
            'termineAt'          => 'datetime',
            'archivedAt'         => 'datetime',
            'objectifMontant'    => 'integer',
            'montantCollecte'    => 'integer',
            'nombreContributions'=> 'integer',
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

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class, 'evenementId');
    }

    public function stateTransitions(): HasMany
    {
        return $this->hasMany(EvenementStateTransition::class, 'evenementId');
    }

    // ─── Helpers d'état ───────────────────────────────────────────────────────

    public function estActif(): bool
    {
        return $this->statut === self::STATUT_ACTIF;
    }

    public function estVisiblePour(User $user): bool
    {
        if ($user->isAdmin() || $this->auteurId === $user->id) {
            return true;
        }

        return in_array($this->statut, [self::STATUT_ACTIF, self::STATUT_TERMINE], true);
    }

    /**
     * Nombre de jours restants avant l'archivage automatique (null si non pertinent).
     */
    public function joursAvantArchivage(): ?int
    {
        if ($this->statut !== self::STATUT_TERMINE || !$this->termineAt) {
            return null;
        }

        $archivageA = $this->termineAt->copy()->addDays(self::JOURS_AVANT_ARCHIVAGE);
        $jours = (int) ceil(now()->diffInHours($archivageA, false) / 24);

        return max(0, $jours);
    }

    /**
     * Pourcentage de progression vers l'objectif financier (null si pas d'objectif).
     */
    public function pourcentageObjectif(): ?float
    {
        if (!$this->objectifMontant || $this->objectifMontant <= 0) {
            return null;
        }

        return round(min(100, ($this->montantCollecte / $this->objectifMontant) * 100), 1);
    }
}
