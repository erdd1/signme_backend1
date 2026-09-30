<?php

namespace App\Modules\AboutChurch\Http\Requests;

use App\Models\AboutChurchArticle;
use App\Modules\AboutChurch\Http\Requests\Rules\MaxWordsRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateAboutChurchArticleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type'          => ['required', 'string', Rule::in(AboutChurchArticle::TYPES)],
            'titre'         => ['required', 'string', 'min:3', 'max:255'],
            'contenu'       => ['required', 'string', 'min:10', new MaxWordsRule()],
            'images'        => ['nullable', 'array', 'max:' . AboutChurchArticle::MAX_IMAGES],
            'images.*'      => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'statutPasteur' => [
                Rule::requiredIf(fn () => $this->input('type') === AboutChurchArticle::TYPE_PASTOR),
                'nullable',
                Rule::in(AboutChurchArticle::STATUTS_PASTEUR),
            ],
            // Admin uniquement — ignoré pour pasteur/secrétaire (churchId dérivé de l'auth)
            'churchId' => ['nullable', 'integer', 'exists:churches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required'          => 'Le type d\'article est obligatoire.',
            'type.in'                => 'Type d\'article invalide.',
            'titre.required'         => 'Le titre est obligatoire.',
            'titre.min'              => 'Le titre doit contenir au moins 3 caractères.',
            'contenu.required'       => 'Le contenu est obligatoire.',
            'contenu.min'            => 'Le contenu doit contenir au moins 10 caractères.',
            'images.max'             => 'Maximum ' . AboutChurchArticle::MAX_IMAGES . ' photos par article.',
            'images.*.image'         => 'Chaque fichier doit être une image.',
            'images.*.mimes'         => 'Formats acceptés : JPG, PNG ou WebP.',
            'images.*.max'           => 'Chaque photo ne doit pas dépasser 2 Mo.',
            'statutPasteur.required' => 'Le statut du pasteur (actuel/ancien) est obligatoire.',
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
