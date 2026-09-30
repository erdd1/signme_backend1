<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Modèle Celebration — Instance générée par SendCelebrationPushesJob pour un
 * utilisateur donné, à une date donnée. Alimente la liste "Célébrations" et
 * le suivi lu/non-lu ; l'uuid sert de cible au deep-link du push.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $userId
 * @property int         $churchId
 * @property int         $celebrationTypeId
 * @property \Illuminate\Support\Carbon $occursOn
 * @property bool        $isRead
 * @property \Illuminate\Support\Carbon|null $readAt
 */
class Celebration extends Model
{
    protected $fillable = [
        'uuid',
        'userId',
        'churchId',
        'celebrationTypeId',
        'occursOn',
        'isRead',
        'readAt',
    ];

    protected $casts = [
        'occursOn' => 'date',
        'isRead'   => 'boolean',
        'readAt'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'churchId');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CelebrationType::class, 'celebrationTypeId');
    }

    public function markAsRead(): void
    {
        if ($this->isRead) {
            return;
        }
        $this->update(['isRead' => true, 'readAt' => now()]);
    }
}
