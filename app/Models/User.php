<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Modèle User — EEC SignCard
 *
 * Table centrale : tous les membres (6 rôles) sont dans ce modèle.
 *
 * @property int              $id
 * @property string           $nom
 * @property string           $email
 * @property string           $role
 * @property int|null         $churchId
 * @property bool             $actif
 * @property bool             $est_de_service
 * @property string|null      $photoUrl
 * @property string|null      $telephone
 * @property string|null      $profession
 * @property string|null      $originaireDe                          Région d'origine (App\Models\User::REGIONS)
 * @property string|null      $dateConfirmation
 * @property string|null      $lieuConfirmation
 * @property string|null      $sexe                                  homme|femme
 * @property string|null      $matricule                             Identifiant propre à l'église (H001-H999 / F001-F999)
 * @property bool             $estCommuniant                         false uniquement possible pour le rôle paroissien
 * @property \Carbon\Carbon|null $communiantDepuis                     Posée au passage false→true (plancher pour signer)
 * @property \Carbon\Carbon|null $dateNaissance
 * @property int|null         $quartierResidenceId
 * @property int|null         $villeResidenceId
 * @property \Carbon\Carbon|null $last_read_historique_at              Dernière visite onglet Historique
 * @property \Carbon\Carbon|null $last_read_historique_signatures_at   Dernière visite onglet Historique Signatures
 * @property \Carbon\Carbon|null $last_read_evenements_at              Dernière visite du fil d'actualité (badge Événements)
 * @property \Carbon\Carbon|null $last_read_transactions_at           Dernière consultation de « Mes transactions » (badge Services)
 */
    class User extends Authenticatable
    {
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Envoie l'email de réinitialisation (lien deep link SignMe + code).
     */

    // ─── Rôles disponibles ────────────────────────────────────────────────────
    public const ROLE_ADMIN      = 'administrateur';
    public const ROLE_PASTEUR    = 'pasteur';
    public const ROLE_SECRETAIRE = 'secretaire';
    public const ROLE_TRESORIER  = 'tresorier';
    public const ROLE_ANCIEN     = 'ancien';
    public const ROLE_PAROISSIEN = 'paroissien';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_PASTEUR,
        self::ROLE_SECRETAIRE,
        self::ROLE_TRESORIER,
        self::ROLE_ANCIEN,
        self::ROLE_PAROISSIEN,
    ];

    // ─── Démographie ────────────────────────────────────────────────────────
    public const SEXE_HOMME = 'homme';
    public const SEXE_FEMME = 'femme';

    public const SEXES = [
        self::SEXE_HOMME,
        self::SEXE_FEMME,
    ];

    /** Format de l'identifiant propre à l'église : H/F + 3 chiffres (ex. H011, F112). */
    public const MATRICULE_REGEX = '/^[HF]\d{3}$/';

    /** Message affiché à un paroissien non communiant sur toute fonctionnalité bloquée. */
    public const MSG_NOT_COMMUNIANT = "Vous ne pouvez pas avoir accès à cette fonctionnalité car vous n'êtes pas communiant.";

    public const REGIONS = [
        'Adamaoua',
        'Centre',
        'Est',
        'Extrême-Nord',
        'Littoral',
        'Nord',
        'Nord-Ouest',
        'Ouest',
        'Sud',
        'Sud-Ouest',
    ];

    protected $fillable = [
        'nom',
        'email',
        'password',
        'churchId',
        // 'role', 'actif', 'est_de_service', 'estCommuniant' et
        // 'communiantDepuis' sont volontairement EXCLUS : champs sensibles
        // (privilège/statut de compte), assignés explicitement via
        // propriété + save() dans les services concernés, jamais via
        // mass-assignment.
        'photoUrl',
        'telephone',
        'profession',
        'originaireDe',
        'dateConfirmation',
        'lieuConfirmation',
        // ─── Démographie ────────────────────────────────────────────────
        'sexe',
        'matricule',
        'dateNaissance',
        'quartierResidenceId',
        'villeResidenceId',
        // ─── Badges de lecture ────────────────────────────────────────────
        'last_read_historique_at',
        'last_read_historique_signatures_at',
        'last_read_evenements_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'actif'                                => 'boolean',
            'is_super_admin'                       => 'boolean',
            'est_de_service'                       => 'boolean',
            'estCommuniant'                        => 'boolean',
            'communiantDepuis'                     => 'date',
            'dateConfirmation'                     => 'date',
            'dateNaissance'                         => 'date',
            'password'                             => 'hashed',
            // ─── Badges ──────────────────────────────────────────────────
            'last_read_historique_at'              => 'datetime',
            'last_read_historique_signatures_at'   => 'datetime',
            'last_read_evenements_at'              => 'datetime',
            'last_read_transactions_at'            => 'datetime',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function quartier(): BelongsTo
    {
        return $this->belongsTo(Quartier::class, 'quartierResidenceId');
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class, 'villeResidenceId');
    }

    /**
     * Groupes du membre, avec son poste dans chacun (pivot estPresident /
     * estTresorier — aucun des deux = simple membre). Sans rapport avec
     * `users.role`.
     */
    public function groupes(): BelongsToMany
    {
        return $this->belongsToMany(Groupe::class, 'groupe_user', 'userId', 'groupeId')
            ->withPivot(['estPresident', 'estTresorier', 'lastReadAt'])
            ->withTimestamps();
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'userId');
    }

    public function signaturesEffectuees(): HasMany
    {
        return $this->hasMany(Signature::class, 'ancienId');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class, 'auteurId');
    }

    /**
     * Jetons FCM (notifications push) de cet utilisateur — un par appareil.
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class, 'userId');
    }

    /**
     * Publications lues par cet utilisateur (via publication_reads)
     */
    public function publicationReads(): HasMany
    {
        return $this->hasMany(PublicationRead::class, 'userId');
    }

    public function ancienDesignations(): HasMany
    {
        return $this->hasMany(AncienDesignation::class, 'ancienId');
    }

    // ─── Relations de sécurité (PHASE 1) ──────────────────────────────────

    /**
     * Refresh tokens de cet utilisateur
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * Logs de sécurité de cet utilisateur
     */
    public function securityLogs(): HasMany
    {
        return $this->hasMany(SecurityLog::class);
    }

    /**
     * Vérifications d'email de cet utilisateur
     */
    public function emailVerifications(): HasMany
    {
        return $this->hasMany(EmailVerification::class);
    }

    // ─── Relations de sécurité (PHASE 2) ──────────────────────────────────

    /**
     * Credentials MFA
     */
    public function mfaCredential()
    {
        return $this->hasOne(MFACredential::class);
    }

    /**
     * Sessions appareils de cet utilisateur
     */
    public function deviceSessions(): HasMany
    {
        return $this->hasMany(DeviceSession::class);
    }

    /**
     * Activités suspectes de cet utilisateur
     */
    public function suspiciousActivities(): HasMany
    {
        return $this->hasMany(SuspiciousActivity::class);
    }

    /**
     * Codes OTP de cet utilisateur
     */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(OTPCode::class);
    }

    /**
     * Permissions admin par module (mini-admins uniquement — sans effet
     * pour un super admin, qui a toujours accès à tout).
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(AdminPermission::class);
    }

    // ─── Helpers de rôle ──────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && $this->is_super_admin;
    }

    /**
     * Un super admin a toujours accès à tout. Un mini-admin n'a accès à un
     * module que si une ligne admin_permissions existe explicitement pour
     * ce module avec l'action demandée activée — l'absence de ligne
     * équivaut à "tout refusé", jamais à "tout autorisé".
     */
    public function hasPermission(string $resource, string $action): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (!$this->isAdmin()) {
            return false;
        }

        $column = match ($action) {
            'view' => 'can_view',
            'create' => 'can_create',
            'update' => 'can_update',
            'delete' => 'can_delete',
            default => null,
        };

        if ($column === null) {
            return false;
        }

        return $this->permissions->firstWhere('resource', $resource)?->{$column} ?? false;
    }

    public function isPasteur(): bool
    {
        return $this->role === self::ROLE_PASTEUR;
    }

    public function isSecretaire(): bool
    {
        return $this->role === self::ROLE_SECRETAIRE;
    }

    public function isTresorier(): bool
    {
        return $this->role === self::ROLE_TRESORIER;
    }

    public function isAncien(): bool
    {
        return $this->role === self::ROLE_ANCIEN;
    }

    public function isParoissien(): bool
    {
        return $this->role === self::ROLE_PAROISSIEN;
    }

    public function canManageChurch(): bool
    {
        return in_array($this->role, [
            self::ROLE_ADMIN,
            self::ROLE_PASTEUR,
            self::ROLE_SECRETAIRE,
        ]);
    }

    public function canSignPresentiel(): bool
    {
        if (in_array($this->role, [self::ROLE_ADMIN, self::ROLE_PASTEUR], true)) {
            return true;
        }
        return $this->role === self::ROLE_ANCIEN && $this->est_de_service;
    }

    /**
     * Vérifie si l'utilisateur appartient à une église donnée
     */
    public function belongsToChurch(int $churchId): bool
    {
        if ($this->isAdmin()) {
            return true; // Admin voit tout
        }
        return $this->churchId === $churchId;
    }

    /**
     * Vérifie si l'utilisateur a un ou plusieurs rôles spécifiés
     * 
     * @param string|array $roles Un rôle ou un tableau de rôles à vérifier
     * @return bool
     */
    public function hasRole($roles): bool
    {
        if (is_string($roles)) {
            return $this->role === $roles;
        }
        if (is_array($roles)) {
            return in_array($this->role, $roles, true);
        }
        return false;
    }
}
