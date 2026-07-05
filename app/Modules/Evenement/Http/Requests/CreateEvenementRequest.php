<?php

namespace App\Modules\Evenement\Http\Requests;

use App\Modules\Evenement\Application\Services\EvenementService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateEvenementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titre'              => ['required', 'string', 'min:5', 'max:150'],
            'description'         => ['required', 'string', 'min:20', 'max:5000'],
            'image'               => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:100', 'dimensions:max_width=4096,max_height=4096'],
            'dateDebut'           => ['required', 'date'],
            'dateFin'             => ['nullable', 'date', 'after:dateDebut'],
            'objectifMontant'     => ['nullable', 'integer', 'min:1'],
            'mode'                => ['required', 'string', Rule::in([
                EvenementService::MODE_BROUILLON,
                EvenementService::MODE_IMMEDIATE,
                EvenementService::MODE_PLANIFIE,
            ])],
            'scheduledPublishAt'  => ['required_if:mode,' . EvenementService::MODE_PLANIFIE, 'nullable', 'date', 'after:now'],
            'churchId'            => ['nullable', 'integer', 'exists:churches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'titre.required'             => 'Le titre est obligatoire.',
            'titre.min'                   => 'Le titre doit contenir au moins 5 caractères.',
            'titre.max'                   => 'Le titre ne peut pas dépasser 150 caractères.',
            'description.required'       => 'La description est obligatoire.',
            'description.min'            => 'La description doit contenir au moins 20 caractères.',
            'description.max'            => 'La description ne peut pas dépasser 5000 caractères.',
            'image.max'                   => 'L\'image ne doit pas dépasser 100 Ko.',
            'image.dimensions'            => 'Image trop grande (max. 4096×4096 px).',
            'dateDebut.required'         => 'La date de début est obligatoire.',
            'dateFin.after'              => 'La date de fin doit être supérieure à la date de début.',
            'objectifMontant.min'        => 'L\'objectif financier doit être un montant positif.',
            'mode.required'              => 'Le mode de publication est obligatoire.',
            'scheduledPublishAt.required_if' => 'La date/heure de publication planifiée est requise.',
            'scheduledPublishAt.after'   => 'La date de publication planifiée doit être dans le futur.',
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
