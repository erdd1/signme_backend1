<?php

namespace App\Modules\Retrait\Http\Resources;

use App\Models\Retrait;
use Illuminate\Http\Resources\Json\JsonResource;

class RetraitResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Retrait $this */
        return [
            'uuid'            => $this->uuid,
            'montantDemande'  => $this->montant_demande,
            'frais'           => $this->frais,
            'montantNet'      => $this->montant_net,
            'statut'          => $this->statut,
            'reference'       => $this->reference,
            'motifEchec'      => $this->motif_echec,
            'motif'           => $this->motif,
            'soldeApres'      => $this->solde_apres,
            'beneficiaire'    => $this->beneficiaire ? [
                'operator'     => $this->beneficiaire->operatorLabel(),
                'numeroMasque' => $this->beneficiaire->maskedAccountNumber(),
            ] : null,
            'auteur' => [
                'id'  => $this->auteur?->id,
                'nom' => $this->auteur?->nom,
            ],
            'completedAt' => $this->completed_at?->toIso8601String(),
            'createdAt'   => $this->created_at?->toIso8601String(),
        ];
    }
}
