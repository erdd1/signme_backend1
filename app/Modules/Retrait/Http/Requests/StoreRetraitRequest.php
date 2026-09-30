<?php

namespace App\Modules\Retrait\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRetraitRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $montantMin = (int) config('payment.retrait.montant_min', 1000);

        return [
            'montant' => "required|integer|min:{$montantMin}",
        ];
    }

    public function messages(): array
    {
        $montantMin = (int) config('payment.retrait.montant_min', 1000);

        return [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min'      => "Le montant minimum de retrait est de {$montantMin} FCFA.",
        ];
    }
}
