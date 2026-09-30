<?php

namespace App\Modules\Retrait\Http\Resources;

use App\Models\RetraitBeneficiaire;
use Illuminate\Http\Resources\Json\JsonResource;

class RetraitBeneficiaireResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var RetraitBeneficiaire $this */
        return [
            'uuid'                 => $this->uuid,
            'operator'             => $this->operatorLabel(),
            'channel'              => $this->channel,
            'numeroMasque'         => $this->maskedAccountNumber(),
            'createdAt'            => $this->created_at?->toIso8601String(),
        ];
    }
}
