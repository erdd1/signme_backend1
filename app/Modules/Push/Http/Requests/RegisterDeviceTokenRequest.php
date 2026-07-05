<?php

namespace App\Modules\Push\Http\Requests;

use App\Models\DeviceToken;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', Rule::in(DeviceToken::PLATFORMS)],
            // Fuseau IANA de l'appareil (ex. "Africa/Douala") — utilisé pour
            // programmer les notifications d'anniversaire à l'heure locale.
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'    => 'Le jeton de l\'appareil est obligatoire.',
            'platform.required' => 'La plateforme est obligatoire.',
            'platform.in'       => 'Plateforme invalide. Valeurs acceptées : ' . implode(', ', DeviceToken::PLATFORMS),
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
