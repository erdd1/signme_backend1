<?php

namespace App\Modules\AncienDesignation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class DesignerAncienRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ancien_id'       => ['required', 'integer', 'exists:users,id'],
            'mois'            => ['required', 'integer', 'min:1', 'max:12'],
            'annee'           => ['required', 'integer', 'min:2020', 'max:2099'],
            'church_id'       => ['nullable', 'integer', 'exists:churches,id'],
            'jours_travail'   => ['nullable', 'array', 'min:1', 'max:7'],
            'jours_travail.*' => ['integer', 'min:1', 'max:7'],
            'horaire_debut'   => ['nullable', 'date_format:H:i'],
            'horaire_fin'     => ['nullable', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'ancien_id.required'       => 'L\'identifiant de l\'ancien est obligatoire.',
            'ancien_id.exists'         => 'Cet ancien n\'existe pas.',
            'mois.required'            => 'Le mois est obligatoire.',
            'mois.min'                 => 'Le mois doit être entre 1 et 12.',
            'mois.max'                 => 'Le mois doit être entre 1 et 12.',
            'annee.required'           => 'L\'année est obligatoire.',
            'church_id.exists'         => 'L\'église spécifiée n\'existe pas.',
            'jours_travail.array'      => 'Les jours de travail doivent être un tableau.',
            'jours_travail.*.integer'  => 'Chaque jour doit être un entier entre 1 et 7.',
            'jours_travail.*.min'      => 'Chaque jour doit être entre 1 (lundi) et 7 (dimanche).',
            'jours_travail.*.max'      => 'Chaque jour doit être entre 1 (lundi) et 7 (dimanche).',
            'horaire_debut.date_format' => 'L\'horaire début doit être au format HH:MM (ex: 08:00).',
            'horaire_fin.date_format'   => 'L\'horaire fin doit être au format HH:MM (ex: 18:00).',
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
