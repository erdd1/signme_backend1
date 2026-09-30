<?php

namespace App\Modules\AboutChurch\Http\Requests;

use App\Models\AboutChurchArticle;
use App\Modules\AboutChurch\Http\Requests\Rules\MaxWordsRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * NB : le champ `type` n'est volontairement pas modifiable après création —
 * la catégorisation d'un article est fixée à la création (créer un nouvel
 * article plutôt que reclasser un article existant).
 *
 * NB : gestion des photos — trois champs distincts, jamais de fusion
 * implicite basée sur l'index :
 *   - `photosTouched` : bool explicite — l'utilisateur a interagi avec la
 *     section photos (ajout et/ou suppression). Indispensable pour
 *     distinguer "je n'ai pas touché aux photos" de "je les ai toutes
 *     retirées" : un tableau `keepImages` vide n'a pas de représentation
 *     non-ambiguë en multipart/form-data (absence de champ == tableau vide).
 *   - `keepImages` : URLs (parmi les photos actuelles de l'article) à
 *     conserver telles quelles.
 *   - `images`     : nouveaux fichiers à uploader, ajoutés après celles de
 *     `keepImages`.
 * Si `photosTouched` n'est pas vrai (et qu'aucun fichier `images` n'est
 * envoyé), les photos existantes sont conservées intégralement. Sinon, le
 * jeu de photos final = keepImages + images (dans cet ordre, plafonné à
 * MAX_IMAGES).
 */
class UpdateAboutChurchArticleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'titre'         => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'contenu'       => ['sometimes', 'required', 'string', 'min:10', new MaxWordsRule()],
            'photosTouched' => ['sometimes', 'boolean'],
            'keepImages'    => ['sometimes', 'array', 'max:' . AboutChurchArticle::MAX_IMAGES],
            'keepImages.*'  => ['string'],
            'images'        => ['sometimes', 'array', 'max:' . AboutChurchArticle::MAX_IMAGES],
            'images.*'      => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'statutPasteur' => ['sometimes', 'nullable', Rule::in(AboutChurchArticle::STATUTS_PASTEUR)],
        ];
    }

    public function messages(): array
    {
        return [
            'titre.required'   => 'Le titre est obligatoire.',
            'contenu.required' => 'Le contenu est obligatoire.',
            'titre.min'        => 'Le titre doit contenir au moins 3 caractères.',
            'contenu.min'      => 'Le contenu doit contenir au moins 10 caractères.',
            'keepImages.max'   => 'Maximum ' . AboutChurchArticle::MAX_IMAGES . ' photos par article.',
            'images.max'       => 'Maximum ' . AboutChurchArticle::MAX_IMAGES . ' photos par article.',
            'images.*.image'   => 'Chaque fichier doit être une image.',
            'images.*.mimes'   => 'Formats acceptés : JPG, PNG ou WebP.',
            'images.*.max'     => 'Chaque photo ne doit pas dépasser 2 Mo.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $total = count($this->input('keepImages', [])) + count($this->file('images', []));
            if ($total > AboutChurchArticle::MAX_IMAGES) {
                $validator->errors()->add(
                    'images',
                    'Maximum ' . AboutChurchArticle::MAX_IMAGES . ' photos au total par article (conservées + nouvelles).'
                );
            }
        });
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
