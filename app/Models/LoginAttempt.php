<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * LoginAttempt Model
 *
 * Suivi des tentatives de connexion pour rate limiting et blocage.
 *
 * @property int         $id
 * @property string      $email
 * @property string      $ip_address
 * @property string|null $user_agent
 * @property int         $failed_count
 * @property \Carbon     $first_attempt_at
 * @property \Carbon|null $blocked_until
 * @property string      $status (pending|success|blocked)
 */
class LoginAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'ip_address',
        'user_agent',
        'failed_count',
        'first_attempt_at',
        'blocked_until',
        'status',
    ];

    protected $casts = [
        'first_attempt_at' => 'datetime',
        'blocked_until'    => 'datetime',
    ];

    // ─── Scopes ──────────────────────────────────────────────────────────

    /**
     * Tentatives récentes (dernières 24h)
     */
    public function scopeRecent($query)
    {
        return $query->where('created_at', '>', now()->subDay());
    }

    /**
     * Tentatives bloquées actuellement
     * Vérifie uniquement si blocked_until est dans le futur
     */
    public function scopeBlocked($query)
    {
        return $query->where('blocked_until', '>', now());
    }

    /**
     * Pour une adresse email
     */
    public function scopeForEmail($query, $email)
    {
        return $query->where('email', $email);
    }

    /**
     * Pour une IP
     */
    public function scopeForIp($query, $ip)
    {
        return $query->where('ip_address', $ip);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Vérifier si le compte est actuellement bloqué
     */
    public function isBlocked(): bool
    {
        return $this->blocked_until && now()->isBefore($this->blocked_until);
    }

    /**
     * Temps restant avant déblocage (en minutes)
     */
    public function remainingBlockMinutes(): int
    {
        if (!$this->isBlocked()) {
            return 0;
        }

        $remaining = now()->diffInMinutes($this->blocked_until);
        
        // Retourner au moins 1 minute si le blocage est toujours actif
        return max(1, $remaining);
    }
}
