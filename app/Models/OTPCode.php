<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OTPCode Model
 *
 * Stocke les codes OTP temporaires pour :
 * - Vérification d'email
 * - MFA
 * - Reset password
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $code (6 chiffres)
 * @property string      $type (email_verification|mfa|password_reset)
 * @property string      $email
 * @property int         $attempts
 * @property bool        $is_used
 * @property \Carbon     $expires_at
 * @property \Carbon|null $used_at
 */
class OTPCode extends Model
{
    use HasFactory;

    protected $table = 'otp_codes';

    protected $fillable = [
        'user_id',
        'code',
        'type',
        'email',
        'attempts',
        'is_used',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'is_used'    => 'boolean',
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * Codes valides (non expirés, non utilisés)
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now())
                     ->where('is_used', false);
    }

    /**
     * Pour un utilisateur et type spécifique
     */
    public function scopeForUserAndType($query, $userId, $type)
    {
        return $query->where('user_id', $userId)
                     ->where('type', $type);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Vérifier si le code est expiré
     */
    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    /**
     * Vérifier si utilisé
     */
    public function isUsed(): bool
    {
        return $this->is_used;
    }

    /**
     * Incrémenter les tentatives
     */
    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    /**
     * Marquer comme utilisé
     */
    public function markAsUsed(): void
    {
        $this->update([
            'is_used' => true,
            'used_at' => now(),
        ]);
    }

    /**
     * Générer un code OTP aléatoire (6 chiffres)
     */
    public static function generateCode(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
