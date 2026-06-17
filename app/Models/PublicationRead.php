<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model PublicationRead — Suivi de lecture des publications
 *
 * Chaque enregistrement signifie qu'un utilisateur a lu une publication.
 * Utilisé pour calculer le badge "non-lus" de l'onglet Home (Accueil).
 *
 * @property int    $id
 * @property int    $userId
 * @property int    $publicationId
 * @property string $readAt
 */
class PublicationRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'userId',
        'publicationId',
        'readAt',
    ];

    protected function casts(): array
    {
        return [
            'readAt' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class, 'publicationId');
    }
}
