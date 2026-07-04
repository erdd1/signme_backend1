<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'email'     => ['required', 'email', 'max:255'],
            'password'  => ['required', 'string', 'min:6'],
            'device_id' => ['nullable', 'string', 'max:255'],  // PHASE 1 : Device tracking
            // PHASE 2 : MFA — OTP à 6 chiffres OU code de secours (8 car. hexadécimaux majuscules)
            'otp'       => ['nullable', 'string', 'regex:/^(\d{6}|[0-9A-F]{8})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'L\'adresse email est obligatoire.',
            'email.email'       => 'L\'adresse email n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min'      => 'Le mot de passe doit contenir au moins 6 caractères.',
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

