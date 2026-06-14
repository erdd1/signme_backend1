<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RefreshToken Model
 *
 * Stocke les refresh tokens avec rotation.
 * Chaque refresh token renouvelle l'accès sans re-login.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $token (unique)
 * @property string|null $device_info
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon     $expires_at
 * @property \Carbon|null $last_used_at
 */
class RefreshToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'device_info',
        'ip_address',
        'user_agent',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'last_used_at' => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────

    /**
     * Tokens valides (non expirés)
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Tokens expirés
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Pour un utilisateur spécifique
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId)->valid();
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
     * Marquer comme utilisé
     */
    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
