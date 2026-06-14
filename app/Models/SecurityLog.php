<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SecurityLog Model
 *
 * Audit trail des actions de sécurité (login, logout, reset password, etc)
 * SANS données sensibles
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $action (login|logout|failed_login|password_reset|token_refresh|blocked|suspicious_activity)
 * @property string      $status (success|failed|blocked|alert)
 * @property string      $ip_address
 * @property string|null $user_agent
 * @property string|null $device_id
 * @property array|null  $details (données sécurisées)
 * @property \Carbon     $created_at
 */
class SecurityLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null; // Immuable après création

    protected $fillable = [
        'user_id',
        'action',
        'status',
        'ip_address',
        'user_agent',
        'device_id',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    // ─── Relations ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────

    /**
     * Logs d'un utilisateur
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Logs d'une action spécifique
     */
    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Logs avec statut
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Logs récents (dernières 24h)
     */
    public function scopeRecent($query)
    {
        return $query->where('created_at', '>', now()->subDay());
    }

    /**
     * Alertes de sécurité
     */
    public function scopeAlerts($query)
    {
        return $query->where('status', 'alert')->orWhere('status', 'blocked');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Créer un log de sécurité formaté
     */
    public static function recordAction(
        ?int $userId,
        string $action,
        string $status = 'success',
        ?string $ipAddress = null,
        ?array $details = null,
        ?string $deviceId = null,
        ?string $userAgent = null
    ): self {
        return self::create([
            'user_id'    => $userId,
            'action'     => $action,
            'status'     => $status,
            'ip_address' => $ipAddress ?? request()->ip(),
            'user_agent' => $userAgent ?? request()->header('User-Agent'),
            'device_id'  => $deviceId,
            'details'    => $details,
        ]);
    }
}
