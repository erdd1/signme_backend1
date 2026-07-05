<?php

namespace App\Modules\UserLookup\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateVilleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nom'         => ['required', 'string', 'max:150'],
            'estDiaspora' => ['required', 'boolean'],
            'churchId'    => ['nullable', 'integer', 'exists:churches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required'         => 'Le nom de la ville est obligatoire.',
            'estDiaspora.required' => 'Veuillez préciser si cette ville est au Cameroun ou hors Cameroun.',
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
