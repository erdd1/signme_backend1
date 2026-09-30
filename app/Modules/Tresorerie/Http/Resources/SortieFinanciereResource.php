<?php
namespace App\Modules\Tresorerie\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\SortieFinanciere;

class SortieFinanciereResource extends JsonResource {
    public function toArray($request): array {
        /** @var SortieFinanciere $this */
        return [
            'uuid'        => $this->uuid,
            'type'        => $this->type,
            'statut'      => $this->statut,
            'montant'     => $this->montant,
            'description' => $this->description,
            'reference'   => $this->reference,
            'motifRejet'  => $this->motif_rejet,
            'soldeApres'  => $this->solde_apres,
            'auteur' => [
                'id'  => $this->auteur?->id,
                'nom' => $this->auteur?->nom,
            ],
            'validatedBy' => $this->validated_by ? [
                'id'  => $this->validatedBy?->id,
                'nom' => $this->validatedBy?->nom,
            ] : null,
            'validatedAt' => $this->validated_at?->toIso8601String(),
            'createdAt'   => $this->created_at?->toIso8601String(),
        ];
    }
}
