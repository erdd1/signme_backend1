<?php

namespace App\Modules\Celebration\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CelebrationTypeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'titre' => $this->titre,
            'messageTemplate' => $this->messageTemplate,
            'dateRuleType' => $this->dateRuleType,
            'fixedMonth' => $this->fixedMonth,
            'fixedDay' => $this->fixedDay,
            'genderFilter' => $this->genderFilter,
            'animationKey' => $this->animationKey,
            'active' => $this->active,
        ];
    }
}
