<?php

namespace App\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource — Sérialisation d'un membre pour l'API.
 *
 * N'expose plus les colonnes legacy texte libre `groupe`/`quartierResidence`
 * (remplacées par les relations structurées quartier/ville/groupes).
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'nom'                => $this->nom,
            'email'              => $this->email,
            'role'               => $this->role,
            'isSuperAdmin'       => $this->when($this->role === 'administrateur', (bool) $this->is_super_admin),
            'churchId'           => $this->churchId,
            'actif'              => $this->actif,
            'est_de_service'     => $this->est_de_service,
            'photoUrl'           => $this->photoUrl,
            'telephone'          => $this->telephone,
            'profession'         => $this->profession,
            'originaireDe'       => $this->originaireDe,
            'dateConfirmation'   => $this->dateConfirmation?->format('Y-m-d'),
            'lieuConfirmation'   => $this->lieuConfirmation,

            // ─── Démographie ────────────────────────────────────────────
            'sexe'               => $this->sexe,
            'dateNaissance'      => $this->dateNaissance?->format('Y-m-d'),

            'quartier' => $this->whenLoaded('quartier', fn () => $this->quartier ? [
                'id'  => $this->quartier->id,
                'nom' => $this->quartier->nom,
            ] : null),

            'ville' => $this->whenLoaded('ville', fn () => $this->ville ? [
                'id'          => $this->ville->id,
                'nom'         => $this->ville->nom,
                'estDiaspora' => $this->ville->estDiaspora,
            ] : null),

            'groupePrincipal' => $this->whenLoaded('groupePrincipal', fn () => $this->groupePrincipal ? [
                'id'  => $this->groupePrincipal->id,
                'nom' => $this->groupePrincipal->nom,
            ] : null),

            'groupes' => $this->whenLoaded('groupes', fn () => $this->groupes->map(fn ($g) => [
                'id'  => $g->id,
                'nom' => $g->nom,
            ])->values()),

            'church' => $this->whenLoaded('church', fn () => $this->church ? [
                'id'    => $this->church->id,
                'nom'   => $this->church->nom,
                'ville' => $this->church->ville,
            ] : null),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
