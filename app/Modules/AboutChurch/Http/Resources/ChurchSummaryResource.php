<?php

namespace App\Modules\AboutChurch\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Champs minimaux de Church nécessaires au Card principal — pas de
 * duplication avec ChurchController (admin CRUD complet), qui reste la
 * seule source pour la gestion administrative des églises.
 */
class ChurchSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'      => $this->id,
            'nom'     => $this->nom,
            'ville'   => $this->ville,
            'region'  => $this->region,
            'adresse' => $this->adresse,
        ];
    }
}
