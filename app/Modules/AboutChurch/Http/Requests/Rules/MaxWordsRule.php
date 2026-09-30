<?php

namespace App\Modules\AboutChurch\Http\Requests\Rules;

use App\Models\AboutChurchArticle;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Limite absolue de longueur d'un contenu d'article, en nombre de mots.
 *
 * Distincte de la pagination d'affichage (~10 lignes/page, calculée côté
 * Flutter à partir de la largeur réelle de l'écran) — cette règle empêche
 * uniquement la création d'un contenu déraisonnablement long, quel que soit
 * le nombre de pages qu'il occupera ensuite à l'affichage.
 */
class MaxWordsRule implements ValidationRule
{
    public function __construct(
        private readonly int $maxWords = AboutChurchArticle::MAX_MOTS_CONTENU,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $wordCount = count(preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY));

        if ($wordCount > $this->maxWords) {
            $fail("Le contenu dépasse la limite maximale de {$this->maxWords} mots (actuellement {$wordCount}).");
        }
    }
}
