<?php

namespace App\Modules\User\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->route('id') ?? $this->route('user');

        return [
            'nom'               => ['sometimes', 'string', 'max:255'],
            'email'             => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password'          => ['nullable', 'string', 'min:8'],
            'role'              => ['sometimes', 'string', Rule::in(User::ROLES)],
            'telephone'         => ['nullable', 'string', 'max:20'],
            'profession'        => ['nullable', 'string', 'max:150'],
            'originaireDe'      => ['nullable', 'string', Rule::in(User::REGIONS)],
            'dateConfirmation'  => ['nullable', 'date'],
            'lieuConfirmation'  => ['nullable', 'string', 'max:255'],

            // ─── Démographie ────────────────────────────────────────────
            'sexe'              => ['nullable', 'string', Rule::in(User::SEXES)],
            'dateNaissance'     => ['nullable', 'date', 'before:today'],
            'quartierId'        => ['nullable', 'integer', 'exists:quartiers,id'],
            'villeId'           => ['nullable', 'integer', 'exists:villes,id'],
            'groupeIds'         => ['nullable', 'array'],
            'groupeIds.*'       => ['integer', 'exists:groupes,id'],
            'groupePrincipalId' => ['nullable', 'integer', 'exists:groupes,id'],
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
