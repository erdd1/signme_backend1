<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle EvenementStateTransition — Journal d'audit des changements d'état
 * d'un événement (qui, quand, ancienne valeur, nouvelle valeur).
 *
 * Table append-only : jamais mise à jour ni supprimée.
 */
class EvenementStateTransition extends Model
{
    protected $fillable = [
        'evenementId',
        'userId',
        'ancienStatut',
        'nouveauStatut',
        'raison',
    ];

    public function evenement(): BelongsTo
    {
        return $this->belongsTo(Evenement::class, 'evenementId');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
