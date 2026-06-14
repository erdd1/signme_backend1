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
            'quartierResidence' => ['nullable', 'string', 'max:150'],
            'profession'        => ['nullable', 'string', 'max:150'],
            'groupe'            => ['nullable', 'string', 'max:100'],
            'originaireDe'      => ['nullable', 'string', 'max:100'],
            'dateConfirmation'  => ['nullable', 'date'],
            'lieuConfirmation'  => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required'    => 'Le nom est obligatoire.',
            'email.required'  => 'L\'email est obligatoire.',
            'email.unique'    => 'Cet email est déjà utilisé.',
            'email.email'     => 'L\'email n\'est pas valide.',
            'role.required'   => 'Le rôle est obligatoire.',
            'role.in'         => 'Rôle invalide. Valeurs acceptées : ' . implode(', ', User::ROLES),
            'password.min'    => 'Le mot de passe doit contenir au moins 8 caractères.',
            'churchId.exists' => 'L\'église spécifiée n\'existe pas.',
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
