<?php

namespace App\Modules\Publication\Http\Requests;

use App\Models\Publication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreatePublicationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type'              => ['required', 'string', Rule::in([Publication::TYPE_ANNONCE, Publication::TYPE_MEDITATION])],
            'titre'             => ['required', 'string', 'min:3', 'max:255'],
            'contenu'           => ['required', 'string', 'min:10'],
            'referenceBiblique' => ['nullable', 'string', 'max:100'],
            'churchId'          => ['nullable', 'integer', 'exists:churches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required'    => 'Le type de publication est obligatoire.',
            'type.in'          => 'Type invalide. Valeurs : annonce, meditation.',
            'titre.required'   => 'Le titre est obligatoire.',
            'titre.min'        => 'Le titre doit contenir au moins 3 caractères.',
            'contenu.required' => 'Le contenu est obligatoire.',
            'contenu.min'      => 'Le contenu doit contenir au moins 10 caractères.',
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
