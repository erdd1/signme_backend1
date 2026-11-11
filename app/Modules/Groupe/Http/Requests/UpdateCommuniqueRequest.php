<?php

namespace App\Modules\Groupe\Http\Requests;

class UpdateCommuniqueRequest extends BaseGroupeRequest
{
    public function rules(): array
    {
        return [
            'titre'           => ['sometimes', 'string', 'max:150'],
            'message'         => ['sometimes', 'string', 'max:2000'],
            'objectifMontant' => ['sometimes', 'nullable', 'integer', 'min:500', 'max:1000000000'],
            'dateDebut'       => ['sometimes', 'nullable', 'date'],
            'dateFin'         => ['sometimes', 'nullable', 'date', 'after_or_equal:dateDebut'],
        ];
    }
}
