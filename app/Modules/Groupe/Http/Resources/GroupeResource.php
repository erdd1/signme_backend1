<?php

namespace App\Modules\Groupe\Http\Resources;

use App\Models\Groupe;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Groupe vu par un de ses membres : son poste et, pour l'écran du groupe, la
 * caisse (visible de tous les membres) et les responsables.
 */
class GroupeResource extends JsonResource
{
    /** @param array<string, mixed>|null $caisse */
    public function __construct($resource, private readonly ?array $caisse = null, private readonly ?array $responsables = null)
    {
        parent::__construct($resource);
    }

    public function toArray($request): array
    {
        /** @var Groupe $this */
        $estPresident = (bool) ($this->pivot->estPresident ?? false);
        $estTresorier = (bool) ($this->pivot->estTresorier ?? false);

        $data = [
            'uuid'         => $this->uuid,
            'nom'          => $this->nom,
            'postes'       => array_values(array_filter([
                $estPresident ? Groupe::POSTE_PRESIDENT : null,
                $estTresorier ? Groupe::POSTE_TRESORIER : null,
            ])),
            'estPresident' => $estPresident,
            'estTresorier' => $estTresorier,
            'nbMembres'    => $this->membres_count ?? null,
        ];

        if ($this->caisse !== null) {
            $data['caisse'] = $this->caisse;
            $data['responsables'] = $this->responsables;
        }

        return $data;
    }
}
