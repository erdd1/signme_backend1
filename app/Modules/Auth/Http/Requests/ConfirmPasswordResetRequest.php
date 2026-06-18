<?php

namespace App\Modules\Auth\Http\Requests;

use App\Core\Rules\StrongPasswordRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ConfirmPasswordResetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'reset_token'           => ['required', 'string'],
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'string', new StrongPasswordRule(), 'confirmed'],
            'password_confirmation' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'reset_token.required'           => 'Token invalide.',
            'email.required'                 => 'L\'adresse email est obligatoire.',
            'password.required'              => 'Le nouveau mot de passe est obligatoire.',
            'password.confirmed'             => 'Les mots de passe ne correspondent pas.',
            'password_confirmation.required' => 'La confirmation du mot de passe est obligatoire.',
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
