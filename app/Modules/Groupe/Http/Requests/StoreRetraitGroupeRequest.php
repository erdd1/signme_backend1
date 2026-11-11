<?php

namespace App\Modules\Groupe\Http\Requests;

class StoreRetraitGroupeRequest extends BaseGroupeRequest
{
    public function rules(): array
    {
        $montantMin = (int) config('payment.retrait.montant_min', 1000);

        return [
            'montant' => ['required', 'integer', "min:{$montantMin}"],
            'motif'   => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $montantMin = (int) config('payment.retrait.montant_min', 1000);

        return [
            'montant.min'    => "Le montant minimum de retrait est de {$montantMin} FCFA.",
            'motif.required' => 'Le motif du retrait est obligatoire : il est visible de tous les membres du groupe.',
        ];
    }
}
