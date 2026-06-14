<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class StoreSignatureRequest
 * 
 * Validation pour créer une signature
 */
class StoreSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'card_id' => 'required|exists:cards,id',
            'signature_data' => 'required|string',
            'signed_at' => 'required|date',
            // À compléter selon vos besoins
        ];
    }

    public function messages(): array
    {
        return [
            'card_id.required' => 'The card ID is required',
            'card_id.exists' => 'The card does not exist',
            'signature_data.required' => 'The signature data is required',
        ];
    }
}
