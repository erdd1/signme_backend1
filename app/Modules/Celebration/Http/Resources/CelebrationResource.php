<?php

namespace App\Modules\Celebration\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CelebrationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->key,
            'label' => $this->type->label,
            'titre' => $this->type->titre,
            'message' => $this->type->renderMessage($this->user, $this->church, $this->occursOn?->year),
            'animationKey' => $this->type->animationKey,
            'occursOn' => $this->occursOn?->format('Y-m-d'),
            'isRead' => $this->isRead,
            'readAt' => $this->readAt?->toIso8601String(),
            // Utiles côté admin (liste multi-utilisateurs) ; redondants mais
            // inoffensifs côté client (juste ses propres nom/église).
            'userNom' => $this->user?->nom,
            'churchNom' => $this->church?->nom,
        ];
    }
}
