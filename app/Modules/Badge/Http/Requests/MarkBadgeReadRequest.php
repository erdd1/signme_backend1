<?php

namespace App\Modules\Badge\Http\Requests;

use App\Modules\Badge\Application\Services\BadgeService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la requête mark-read.
 *
 * Le champ `type` doit être l'un des types gérés par BadgeService.
 */
class MarkBadgeReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth gérée par le middleware sanctum
    }

    public function rules(): array
    {
        $validTypes = implode(',', [
            BadgeService::TYPE_PUBLICATIONS,
            BadgeService::TYPE_EVENEMENTS,
            BadgeService::TYPE_HISTORIQUE,
            BadgeService::TYPE_HISTORIQUE_SIGNATURES,
        ]);

        return [
            'type' => "required|string|in:{$validTypes}",
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Le type de badge est obligatoire.',
            'type.in'       => 'Type invalide. Valeurs acceptées : publications, evenements, historique, historique_signatures.',
        ];
    }
}
