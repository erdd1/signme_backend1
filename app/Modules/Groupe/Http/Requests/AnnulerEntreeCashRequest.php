<?php

namespace App\Modules\Groupe\Http\Requests;

class AnnulerEntreeCashRequest extends BaseGroupeRequest
{
    public function rules(): array
    {
        return ['motif' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['motif.required' => 'Le motif d\'annulation est obligatoire.'];
    }
}
