<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyOTPRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp'  => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'type' => ['required', 'in:email_verification,mfa,password_reset'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'Le code OTP est obligatoire.',
            'otp.size'     => 'Le code OTP doit contenir exactement 6 chiffres.',
            'otp.regex'    => 'Le code OTP doit être 6 chiffres uniquement.',
            'type.required' => 'Le type de vérification est obligatoire.',
            'type.in'      => 'Type de vérification invalide.',
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
