<?php

namespace App\Modules\AboutChurch\Http\Requests;

use App\Models\ChurchAboutInfo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateChurchAboutInfoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'district'            => ['nullable', 'string', 'max:150'],
            'horairesCulte'       => ['nullable', 'string', 'max:255'],
            'activitesMajeures'   => ['nullable', 'array', 'max:' . ChurchAboutInfo::MAX_ACTIVITES_MAJEURES],
            'activitesMajeures.*' => ['string', 'max:' . ChurchAboutInfo::MAX_LONGUEUR_ACTIVITE],
            // Admin uniquement — ignoré pour pasteur/secrétaire (churchId dérivé de l'auth)
            'churchId' => ['nullable', 'integer', 'exists:churches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'activitesMajeures.max'   => 'Maximum ' . ChurchAboutInfo::MAX_ACTIVITES_MAJEURES . ' activités majeures.',
            'activitesMajeures.*.max' => 'Chaque activité doit tenir sur une ligne (max ' . ChurchAboutInfo::MAX_LONGUEUR_ACTIVITE . ' caractères).',
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
