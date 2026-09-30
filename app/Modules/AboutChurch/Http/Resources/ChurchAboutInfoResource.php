<?php

namespace App\Modules\AboutChurch\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ChurchAboutInfoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'district'          => $this->district,
            'horairesCulte'     => $this->horairesCulte,
            'activitesMajeures' => $this->activitesMajeures ?? [],
        ];
    }
}
