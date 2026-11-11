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
            'matricule'          => $this->matricule,
            'estCommuniant'      => $this->estCommuniant,
            'communiantDepuis'   => $this->communiantDepuis?->format('Y-m-d'),
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

            // Poste dans chaque groupe : [] = simple membre, sinon 'president'
            // et/ou 'tresorier' (sans rapport avec le rôle `role`).
            'groupes' => $this->whenLoaded('groupes', fn () => $this->groupes->map(fn ($g) => [
                'id'     => $g->id,
                'nom'    => $g->nom,
                'postes' => array_values(array_filter([
                    $g->pivot->estPresident ? \App\Models\Groupe::POSTE_PRESIDENT : null,
                    $g->pivot->estTresorier ? \App\Models\Groupe::POSTE_TRESORIER : null,
                ])),
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
