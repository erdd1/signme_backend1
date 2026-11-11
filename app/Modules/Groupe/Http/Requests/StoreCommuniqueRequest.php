<?php

namespace App\Modules\Groupe\Http\Requests;

class StoreCommuniqueRequest extends BaseGroupeRequest
{
    public function rules(): array
    {
        return [
            'titre'           => ['required', 'string', 'max:150'],
            'message'         => ['required', 'string', 'max:2000'],
            'objectifMontant' => ['nullable', 'integer', 'min:500', 'max:1000000000'],
            'dateDebut'       => ['nullable', 'date'],
            'dateFin'         => ['nullable', 'date', 'after_or_equal:dateDebut'],
        ];
    }

    public function messages(): array
    {
        return [
            'titre.required'   => 'Le titre est obligatoire.',
            'message.required' => 'Le message est obligatoire.',
            'objectifMontant.min' => 'L\'objectif doit être d\'au moins 500 FCFA.',
            'dateFin.after_or_equal' => 'La date de fin doit suivre la date de début.',
        ];
    }
}
