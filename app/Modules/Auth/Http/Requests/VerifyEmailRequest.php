<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'otp'   => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'email' => ['required', 'email'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'Le code OTP est obligatoire.',
            'otp.size'     => 'Le code OTP doit contenir 6 chiffres.',
            'email.required' => 'L\'email est obligatoire.',
            'email.email'   => 'L\'email n\'est pas valide.',
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
