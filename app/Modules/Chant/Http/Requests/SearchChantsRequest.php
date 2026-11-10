<?php

namespace App\Modules\Chant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchChantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Saisissez un titre ou un extrait de paroles.',
            'q.min'      => 'Saisissez au moins 2 caractères.',
            'q.max'      => 'La recherche ne peut pas dépasser 120 caractères.',
        ];
    }
}
