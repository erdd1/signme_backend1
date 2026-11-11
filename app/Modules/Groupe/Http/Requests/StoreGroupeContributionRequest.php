<?php

namespace App\Modules\Groupe\Http\Requests;

use App\Modules\Evenement\Http\Requests\CreateContributionRequest;

/**
 * Contribution ou don à un groupe : mêmes champs et mêmes règles (montant,
 * frais, Mobile Money / carte) qu'une contribution d'événement, plus le
 * communiqué visé (absent = don libre au groupe).
 */
class StoreGroupeContributionRequest extends CreateContributionRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['groupeId']); // le groupe est celui de l'URL, jamais envoyé par le client
        $rules['communiqueUuid'] = ['nullable', 'uuid'];

        return $rules;
    }
}
