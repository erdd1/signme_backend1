<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle AncienDesignation — Désignation des anciens de service
 */
class AncienDesignation extends Model
{
    use HasFactory;

    protected $table = 'ancien_designations';

    protected $fillable = [
        'ancienId',
        'ancienNom',
        'egliseId',
        'mois',
        'annee',
        'designePar',
        'designeParNom',
        'jours_travail',
        'horaire_debut',
        'horaire_fin',
        'date_fin_service',
    ];

    protected function casts(): array
    {
        return [
            'mois'              => 'integer',
            'annee'             => 'integer',
            'jours_travail'     => 'json',
            'date_fin_service'  => 'datetime',
        ];
    }

    public function ancien(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ancienId');
    }

    public function eglise(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'egliseId');
    }

    public function designateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designePar');
    }
}
