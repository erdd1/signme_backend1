<?php

namespace App\Modules\AboutChurch\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NB : aucune date de publication exposée (created_at/updated_at) — cette
 * page est une présentation permanente de l'église, pas un fil d'actualité
 * (voir specs). `pinnedAt` est inclus uniquement pour permettre au client de
 * trier les éléments épinglés par ordre d'épinglage dans les carrousels —
 * il n'est jamais affiché tel quel à l'utilisateur.
 */
class AboutChurchArticleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'          => $this->uuid,
            'type'          => $this->type,
            'titre'         => $this->titre,
            'contenu'       => $this->contenu,
            'images'        => $this->images ?? [],
            'statutPasteur' => $this->statutPasteur,
            'isPinned'      => (bool) $this->isPinned,
            'pinnedAt'      => $this->pinnedAt?->toIso8601String(),
            'auteurNom'     => $this->auteurNom,
        ];
    }
}
