<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne = les droits (voir/créer/modifier/supprimer) d'un mini-admin
 * sur un module donné (voir App\Core\Services\AdminResourceRegistry).
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $resource
 * @property bool   $can_view
 * @property bool   $can_create
 * @property bool   $can_update
 * @property bool   $can_delete
 */
class AdminPermission extends Model
{
    protected $fillable = [
        'user_id',
        'resource',
        'can_view',
        'can_create',
        'can_update',
        'can_delete',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_create' => 'boolean',
        'can_update' => 'boolean',
        'can_delete' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
