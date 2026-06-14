<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Modules\Auth\Notifications\ResetPasswordNotification;
use Laravel\Sanctum\HasApiTokens;

/**
 * Modèle User — EEC SignCard
 *
 * Table centrale : tous les membres (6 rôles) sont dans ce modèle.
 *
 * @property int         $id
 * @property string      $nom
 * @property string      $email
 * @property string      $role
 * @property int|null    $churchId
 * @property bool        $actif
 * @property bool        $est_de_service
 * @property string|null $photoUrl
 * @property string|null $telephone
 * @property string|null $quartierResidence
 * @property string|null $profession
 * @property string|null $groupe
 * @property string|null $originaireDe
 * @property string|null $dateConfirmation
 * @property string|null $lieuConfirmation
 */
class User extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable;

    /**
     * Envoie l'email de réinitialisation (lien deep link SignMe + code).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

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

    protected $fillable = [
        'nom',
        'email',
        'password',
        'role',
        'churchId',
        'actif',
        'est_de_service',
        'photoUrl',
        'telephone',
        'quartierResidence',
        'profession',
        'groupe',
        'originaireDe',
        'dateConfirmation',
        'lieuConfirmation',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'actif'            => 'boolean',
            'est_de_service'   => 'boolean',
            'dateConfirmation' => 'date',
            'password'         => 'hashed',
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
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

    // ─── Helpers de rôle ──────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
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
