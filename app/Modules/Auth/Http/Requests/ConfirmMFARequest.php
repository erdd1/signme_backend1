<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ConfirmMFARequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        // Miroir de LoginRequest : accepte aussi un code de secours (8
        // caractères hexadécimaux) en plus du code à 6 chiffres reçu par
        // email — MFAService::confirmMFASetup() gère déjà les deux, seule
        // cette validation empêchait le second de passer.
        return [
            'otp' => ['required', 'string', 'regex:/^(\d{6}|[0-9A-F]{8})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'Le code OTP est obligatoire.',
            'otp.regex'    => 'Le code doit être un code à 6 chiffres ou un code de secours à 8 caractères.',
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
