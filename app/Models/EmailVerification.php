<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmailVerification Model
 *
 * Suivi de la vérification des emails des utilisateurs.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $email
 * @property string      $token (unique)
 * @property bool        $is_verified
 * @property \Carbon|null $verified_at
 * @property \Carbon     $expires_at
 */
class EmailVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'token',
        'is_verified',
        'verified_at',
        'expires_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────

    /**
     * Tokens valides (non expirés et non vérifiés)
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now())
                     ->where('is_verified', false);
    }

    /**
     * Pour un utilisateur
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Pour une adresse email
     */
    public function scopeForEmail($query, $email)
    {
        return $query->where('email', $email);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Vérifier si le token est expiré
     */
    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    /**
     * Marquer comme vérifié
     */
    public function markAsVerified(): void
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }
}
