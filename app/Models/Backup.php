<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Backup Model
 *
 * Historique des sauvegardes de base de données (planifiées, manuelles, ou
 * de sécurité automatique avant une restauration).
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $filename
 * @property string      $type (scheduled|manual|pre_restore_safety)
 * @property string      $status (pending|success|partial|failed)
 * @property array|null  $disks (disques où le fichier a été écrit avec succès)
 * @property int|null    $size_bytes
 * @property string|null $error_message
 * @property int|null    $triggered_by
 * @property \Carbon|null $started_at
 * @property \Carbon|null $completed_at
 */
class Backup extends Model
{
    use HasFactory;

    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_PRE_RESTORE_SAFETY = 'pre_restore_safety';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'filename',
        'type',
        'status',
        'disks',
        'size_bytes',
        'error_message',
        'triggered_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'disks'        => 'array',
        'size_bytes'   => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    // ─── Relations ────────────────────────────────────────────────────────

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────

    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', ['success', 'partial']);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    public function isOffsite(): bool
    {
        return in_array('backups', $this->disks ?? [], true);
    }
}
