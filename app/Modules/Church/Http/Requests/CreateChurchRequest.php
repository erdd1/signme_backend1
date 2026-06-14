<?php

namespace App\Modules\Church\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateChurchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nom'       => ['required', 'string', 'max:255'],
            'ville'     => ['required', 'string', 'max:100'],
            'region'    => ['nullable', 'string', 'max:100'],
            'adresse'   => ['nullable', 'string', 'max:500'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'email'     => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required'   => 'Le nom de l\'église est obligatoire.',
            'ville.required' => 'La ville est obligatoire.',
            'email.email'    => 'L\'adresse email n\'est pas valide.',
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
