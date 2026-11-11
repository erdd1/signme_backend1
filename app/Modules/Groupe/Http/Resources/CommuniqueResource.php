<?php

namespace App\Modules\Groupe\Http\Resources;

use App\Models\GroupeCommunique;
use Illuminate\Http\Resources\Json\JsonResource;

class CommuniqueResource extends JsonResource
{
    /** @param array{collecte: int, nombre: int}|null $stats */
    public function __construct($resource, private readonly ?array $stats = null)
    {
        parent::__construct($resource);
    }

    public function toArray($request): array
    {
        /** @var GroupeCommunique $this */
        $collecte = $this->stats['collecte'] ?? 0;

        return [
            'uuid'            => $this->uuid,
            'titre'           => $this->titre,
            'message'         => $this->message,
            'objectifMontant' => $this->objectifMontant,
            'dateDebut'       => $this->dateDebut?->toDateString(),
            'dateFin'         => $this->dateFin?->toDateString(),
            'montantCollecte' => $collecte,
            'nombreContributions' => $this->stats['nombre'] ?? 0,
            'auteur'          => $this->auteur?->nom,
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }
}
