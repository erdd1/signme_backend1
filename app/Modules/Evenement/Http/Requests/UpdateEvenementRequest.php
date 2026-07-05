<?php

namespace App\Modules\Evenement\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEvenementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titre'           => ['sometimes', 'string', 'min:5', 'max:150'],
            'description'     => ['sometimes', 'string', 'min:20', 'max:5000'],
            'image'           => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:100', 'dimensions:max_width=4096,max_height=4096'],
            'dateFin'         => ['nullable', 'date'],
            'objectifMontant' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'titre.min'           => 'Le titre doit contenir au moins 5 caractères.',
            'titre.max'           => 'Le titre ne peut pas dépasser 150 caractères.',
            'description.min'    => 'La description doit contenir au moins 20 caractères.',
            'description.max'    => 'La description ne peut pas dépasser 5000 caractères.',
            'image.max'           => 'L\'image ne doit pas dépasser 100 Ko.',
            'image.dimensions'    => 'Image trop grande (max. 4096×4096 px).',
            'objectifMontant.min' => 'L\'objectif financier doit être un montant positif.',
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
