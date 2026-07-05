<?php

namespace App\Modules\Signature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Core\Helpers\FeeCalculator;

/**
 * Validation d'une signature à distance (paiement NotchPay : mobile money ou carte)
 */
class CreateSignatureDistanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'months'          => ['required', 'array', 'min:1', 'max:12'],
            'months.*'        => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount'          => ['required', 'integer', 'in:' . implode(',', FeeCalculator::ALLOWED_AMOUNTS)],
            'paymentMethod'   => ['required', 'string', 'in:mobile_money,card'],
            'paymentOperator' => ['required_if:paymentMethod,mobile_money', 'string', 'in:ORANGE MONEY,MTN MOBILE MONEY'],
            'phoneNumber'     => ['required_if:paymentMethod,mobile_money', 'string', 'regex:/^(?:6[0-9]{8}|237[0-9]{9})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'months.required'            => 'Veuillez sélectionner au moins un mois.',
            'months.array'               => 'Format de mois invalide.',
            'months.*.regex'             => 'Format de mois invalide. Utilisez YYYY-MM.',
            'amount.required'            => 'Le montant de l\'offrande est obligatoire.',
            'amount.in'                  => 'Montant invalide. Valeurs autorisées : ' . implode(', ', FeeCalculator::ALLOWED_AMOUNTS) . ' FCFA.',
            'paymentMethod.required'     => 'La méthode de paiement est obligatoire.',
            'paymentMethod.in'           => 'Méthode de paiement invalide. Utilisez : mobile_money ou card.',
            'paymentOperator.required_if' => 'L\'opérateur mobile money est obligatoire.',
            'paymentOperator.in'         => 'Opérateur invalide. Utilisez : ORANGE MONEY ou MTN MOBILE MONEY.',
            'phoneNumber.required_if'    => 'Le numéro de téléphone est obligatoire.',
            'phoneNumber.regex'          => 'Numéro invalide. Format attendu : 237699803165 ou 699803165.',
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
