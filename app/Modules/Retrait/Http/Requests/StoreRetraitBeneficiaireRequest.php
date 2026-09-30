<?php

namespace App\Modules\Retrait\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRetraitBeneficiaireRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'operator' => 'required|string|in:ORANGE MONEY,MTN MOBILE MONEY',
            'phone'    => 'required|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'operator.required' => 'L\'opérateur est obligatoire.',
            'operator.in'       => 'Opérateur invalide (Orange Money ou MTN Mobile Money).',
            'phone.required'    => 'Le numéro de téléphone est obligatoire.',
        ];
    }
}
