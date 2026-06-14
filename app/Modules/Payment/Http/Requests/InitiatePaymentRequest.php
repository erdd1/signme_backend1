<?php

namespace App\Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class InitiatePaymentRequest
 * 
 * Validation pour l'initiation d'un paiement
 */
class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authentifié via middleware
    }

    public function rules(): array
    {
        return [
            'paymentOperator' => 'required|string|in:ORANGE MONEY,MTN MOBILE MONEY',
            'paymentNumber' => 'required|string|regex:/^(?:237|6)\d{8,9}$/',
            'amountPerMonth' => 'required|integer|min:100',
            'monthsCount' => 'required|integer|min:1|max:12',
            'fees' => 'required|integer|min:0',
            'signatureType' => 'required|string|in:presentiel,distance',
            'signatureIds' => 'required|array|min:1',
            'signatureIds.*' => 'integer|exists:signatures,id',
        ];
    }

    public function messages(): array
    {
        return [
            'paymentNumber.regex' => 'Numéro de téléphone invalide. Format attendu: 237XXXXXXXX ou 6XXXXXXXX',
            'amountPerMonth.min' => 'Le montant par mois doit être au minimum 100 XOF',
            'monthsCount.min' => 'Vous devez signer pour au moins 1 mois',
            'monthsCount.max' => 'Vous ne pouvez pas signer pour plus de 12 mois',
            'signatureIds.min' => 'Vous devez sélectionner au moins une signature',
        ];
    }
}
