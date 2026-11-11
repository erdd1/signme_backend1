<?php

namespace App\Modules\Groupe\Http\Requests;

class StoreEntreeCashRequest extends BaseGroupeRequest
{
    public function rules(): array
    {
        return [
            'montant'    => ['required', 'integer', 'min:1', 'max:1000000000'],
            'donateur'   => ['nullable', 'string', 'max:150'],
            'note'       => ['nullable', 'string', 'max:255'],
            'dateEntree' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'montant.required' => 'Le montant est obligatoire.',
            'dateEntree.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ];
    }
}
