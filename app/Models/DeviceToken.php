<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle DeviceToken — Jeton FCM d'un appareil, pour les notifications push.
 */
class DeviceToken extends Model
{
    use HasFactory;

    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_IOS     = 'ios';

    public const PLATFORMS = [
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
    ];

    protected $fillable = [
        'userId',
        'token',
        'platform',
        'timezone',
        'lastSeenAt',
    ];

    protected function casts(): array
    {
        return [
            'lastSeenAt' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
