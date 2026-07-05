<?php

namespace App\Modules\User\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nom'               => ['required', 'string', 'max:255'],
            'email'             => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'          => ['nullable', 'string', 'min:8'],
            'role'              => ['required', 'string', Rule::in(User::ROLES)],
            'churchId'          => ['nullable', 'integer', 'exists:churches,id'],
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

    public function messages(): array
    {
        return [
            'nom.required'         => 'Le nom est obligatoire.',
            'email.required'       => 'L\'email est obligatoire.',
            'email.unique'         => 'Cet email est déjà utilisé.',
            'email.email'          => 'L\'email n\'est pas valide.',
            'role.required'        => 'Le rôle est obligatoire.',
            'role.in'               => 'Rôle invalide. Valeurs acceptées : ' . implode(', ', User::ROLES),
            'password.min'          => 'Le mot de passe doit contenir au moins 8 caractères.',
            'churchId.exists'       => 'L\'église spécifiée n\'existe pas.',
            'originaireDe.in'       => 'Région invalide. Valeurs acceptées : ' . implode(', ', User::REGIONS),
            'sexe.in'               => 'Le sexe doit être "homme" ou "femme".',
            'dateNaissance.before'  => 'La date de naissance doit être dans le passé.',
            'quartierId.exists'     => 'Ce quartier n\'existe pas.',
            'villeId.exists'        => 'Cette ville n\'existe pas.',
            'groupeIds.*.exists'    => 'Un des groupes sélectionnés n\'existe pas.',
            'groupePrincipalId.exists' => 'Le groupe principal sélectionné n\'existe pas.',
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
