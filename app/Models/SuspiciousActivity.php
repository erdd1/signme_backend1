<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SuspiciousActivity Model
 *
 * Enregistre les activités détectées comme suspectes
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $activity_type (multiple_ips|different_countries|brute_force|unusual_time)
 * @property string      $risk_level (low|medium|high|critical)
 * @property string      $ip_address
 * @property string|null $country
 * @property string|null $device_id
 * @property array|null  $details
 * @property bool        $is_blocked
 * @property bool        $is_reviewed
 * @property \Carbon|null $reviewed_at
 */
class SuspiciousActivity extends Model
{
    use HasFactory;

    protected $table = 'suspicious_activities';

    protected $fillable = [
        'user_id',
        'activity_type',
        'risk_level',
        'ip_address',
        'country',
        'device_id',
        'details',
        'is_blocked',
        'is_reviewed',
        'reviewed_at',
    ];

    protected $casts = [
        'is_blocked'  => 'boolean',
        'is_reviewed' => 'boolean',
        'details'     => 'array',
        'reviewed_at' => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * Activités pour un utilisateur
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Par niveau de risque
     */
    public function scopeByRiskLevel($query, $level)
    {
        return $query->where('risk_level', $level);
    }

    /**
     * Non révisées
     */
    public function scopeUnreviewed($query)
    {
        return $query->where('is_reviewed', false);
    }

    /**
     * Bloquées
     */
    public function scopeBlocked($query)
    {
        return $query->where('is_blocked', true);
    }

    /**
     * Récentes
     */
    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('created_at', '>', now()->subHours($hours));
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Marquer comme révisée
     */
    public function markAsReviewed($approved = true): void
    {
        $this->update([
            'is_reviewed' => true,
            'reviewed_at' => now(),
            'is_blocked'  => !$approved,
        ]);
    }

    /**
     * Obtenir le label du risque
     */
    public function getRiskLabel(): string
    {
        return match($this->risk_level) {
            'low'      => '⚠️  Bas',
            'medium'   => '⚠️  Moyen',
            'high'     => '🔴 Élevé',
            'critical' => '🚨 Critique',
            default    => 'Inconnu',
        };
    }
}
