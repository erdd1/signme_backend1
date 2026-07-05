<?php

namespace App\Modules\Evenement\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource MesContribution — Module "Mes dépenses" (carte historique perso).
 * Toujours l'utilisateur concerné : aucune restriction de visibilité ici.
 */
class MesContributionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'            => $this->uuid,
            'evenementTitre'  => $this->evenement?->titre,
            'eglise'          => $this->evenement?->church?->nom,
            'montant'         => $this->montant,
            'frais'           => $this->frais,
            'montantTotal'    => $this->montantTotal,
            'statut'          => $this->statut,
            'paymentMethod'   => $this->paymentMethod,
            'paymentReference' => $this->payment_reference,
            'note'            => $this->note,
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }
}
