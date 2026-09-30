<?php

namespace App\Modules\Celebration\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * NB : seuls titre/messageTemplate/active sont modifiables — voir
 * CelebrationTypeService pour l'explication (clé/règle de date/genre sont
 * structurels, non exposés à l'édition admin).
 */
class UpdateCelebrationTypeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'titre' => ['sometimes', 'required', 'string', 'min:3', 'max:150'],
            'messageTemplate' => ['sometimes', 'required', 'string', 'min:10', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'titre.required' => 'Le titre est obligatoire.',
            'messageTemplate.required' => 'Le message est obligatoire.',
            'messageTemplate.min' => 'Le message doit contenir au moins 10 caractères.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Erreur de validation',
            'errors' => $validator->errors(),
        ], 422));
    }
}
