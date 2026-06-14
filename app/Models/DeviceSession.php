<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DeviceSession Model
 *
 * Suivi des appareils connectés d'un utilisateur pour détecter activité suspecte.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string|null $device_id (mobile_id, browser_id, etc)
 * @property string|null $device_name
 * @property string|null $device_type (mobile|web|tablet)
 * @property string|null $os
 * @property string|null $os_version
 * @property string|null $app_version
 * @property string|null $browser
 * @property string      $ip_address
 * @property string|null $country
 * @property string|null $city
 * @property string|null $user_agent
 * @property bool        $is_trusted
 * @property \Carbon     $last_activity_at
 */
class DeviceSession extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // Immuable après création

    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'device_type',
        'os',
        'os_version',
        'app_version',
        'browser',
        'ip_address',
        'country',
        'city',
        'user_agent',
        'is_trusted',
        'last_activity_at',
    ];

    protected $casts = [
        'is_trusted'      => 'boolean',
        'last_activity_at' => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    /**
     * Sessions actives (dernières 24h)
     */
    public function scopeActive($query)
    {
        return $query->where('last_activity_at', '>', now()->subDay());
    }

    /**
     * Sessions de confiance
     */
    public function scopeTrusted($query)
    {
        return $query->where('is_trusted', true);
    }

    /**
     * Pour un utilisateur
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Mettre à jour l'activité
     */
    public function updateActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Marquer comme approuvé
     */
    public function markAsTrusted(): void
    {
        $this->update(['is_trusted' => true]);
    }

    /**
     * Obtenir le descriptif de l'appareil
     */
    public function getDeviceLabel(): string
    {
        if ($this->device_name) {
            return $this->device_name;
        }

        $parts = [];
        if ($this->device_type === 'mobile' && $this->os) {
            $parts[] = $this->os;
        } elseif ($this->device_type === 'web' && $this->browser) {
            $parts[] = $this->browser;
        }

        if ($this->city) {
            $parts[] = $this->city;
        }

        return implode(' • ', $parts) ?: 'Appareil inconnu';
    }
}
