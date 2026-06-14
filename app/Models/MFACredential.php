<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MFACredential Model
 *
 * Gère les credentials MFA d'un utilisateur (OTP email, codes de secours)
 *
 * @property int         $id
 * @property int         $user_id
 * @property bool        $mfa_enabled
 * @property string      $mfa_type (email|totp)
 * @property string|null $mfa_secret
 * @property array|null  $backup_codes
 * @property \Carbon|null $last_verified_at
 */
class MFACredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mfa_enabled',
        'mfa_type',
        'mfa_secret',
        'backup_codes',
        'last_verified_at',
    ];

    protected $casts = [
        'mfa_enabled'     => 'boolean',
        'backup_codes'    => 'array',
        'last_verified_at' => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Vérifier si MFA est activé
     */
    public function isEnabled(): bool
    {
        return $this->mfa_enabled;
    }

    /**
     * Activer MFA
     */
    public function enable(?string $type = 'email'): void
    {
        $this->update([
            'mfa_enabled' => true,
            'mfa_type'    => $type,
        ]);
    }

    /**
     * Désactiver MFA
     */
    public function disable(): void
    {
        $this->update([
            'mfa_enabled'  => false,
            'mfa_secret'   => null,
            'backup_codes' => null,
        ]);
    }

    /**
     * Générer des codes de secours (8 codes de 8 caractères)
     */
    public static function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // Ex: A1B2C3D4
        }
        return $codes;
    }

    /**
     * Vérifier et utiliser un code de secours
     */
    public function useBackupCode(string $code): bool
    {
        if (!$this->backup_codes || !in_array($code, $this->backup_codes)) {
            return false;
        }

        // Supprimer le code utilisé
        $this->backup_codes = array_values(
            array_filter($this->backup_codes, fn($c) => $c !== $code)
        );
        $this->save();

        return true;
    }
}
