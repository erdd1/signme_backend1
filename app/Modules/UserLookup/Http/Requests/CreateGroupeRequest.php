<?php

namespace App\Modules\UserLookup\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateGroupeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nom'      => ['required', 'string', 'max:150'],
            'churchId' => ['nullable', 'integer', 'exists:churches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom du groupe est obligatoire.',
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
