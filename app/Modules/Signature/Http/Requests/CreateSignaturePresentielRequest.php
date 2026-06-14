<?php

namespace App\Modules\Signature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validation signature présentielle (ancien de service / pasteur / admin)
 */
class CreateSignaturePresentielRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'userId'  => ['required', 'integer', 'exists:users,id'],
            'months'  => ['required', 'array', 'min:1', 'max:12'],
            'months.*'=> ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount'  => ['required', 'integer', 'min:100', 'max:500000'],
        ];
    }

    public function messages(): array
    {
        return [
            'userId.required'  => 'L\'identifiant du paroissien est obligatoire.',
            'userId.exists'    => 'Ce paroissien n\'existe pas.',
            'months.required'  => 'Veuillez sélectionner au moins un mois.',
            'months.*.regex'   => 'Format de mois invalide. Utilisez YYYY-MM.',
            'amount.required'  => 'Le montant de l\'offrande est obligatoire.',
            'amount.min'       => 'Le montant minimum est de 100 FCFA.',
            'amount.max'       => 'Le montant maximum est de 500 000 FCFA.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Erreur de validation',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
