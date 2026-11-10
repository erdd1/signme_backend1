<?php

namespace App\Modules\Evenement\Http\Resources;

use App\Models\Contribution;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource Contribution — masque la note et le numéro de paiement complet
 * sauf pour l'auteur de la contribution, le pasteur, le secrétaire et le
 * trésorier (cf. Contribution::estVisibleNotePar).
 */
class ContributionResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Contribution $this */
        $authUser = $request->user();
        $peutVoirDetails = $authUser && $this->estVisibleNotePar($authUser);

        return [
            'uuid'            => $this->uuid,
            'montant'         => $this->montant,
            'frais'           => $this->frais,
            'montantTotal'    => $this->montantTotal,
            'statut'          => $this->statut,
            'note'            => $peutVoirDetails ? $this->note : null,
            'paymentOperator' => $peutVoirDetails ? $this->paymentOperator : null,
            'paymentNumber'   => $peutVoirDetails && $this->paymentNumber ? $this->maskPhoneNumber($this->paymentNumber) : null,
            'paymentMethod'   => $this->paymentMethod,
            'paymentReference' => $peutVoirDetails ? $this->payment_reference : null,
            'contributeur'    => $peutVoirDetails ? [
                'id'     => $this->user?->id,
                'nom'    => $this->user?->nom,
                'groupe' => $this->groupe ? [
                    'id'  => $this->groupe->id,
                    'nom' => $this->groupe->nom,
                ] : null,
            ] : null,
            'evenement'       => [
                'uuid'  => $this->evenement?->uuid,
                'titre' => $this->evenement?->titre,
            ],
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }

    private function maskPhoneNumber(string $phone): string
    {
        return substr($phone, 0, 4) . '****' . substr($phone, -2);
    }
}
