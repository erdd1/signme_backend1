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
            'quartierResidence' => ['nullable', 'string', 'max:150'],
            'profession'        => ['nullable', 'string', 'max:150'],
            'groupe'            => ['nullable', 'string', 'max:100'],
            'originaireDe'      => ['nullable', 'string', 'max:100'],
            'dateConfirmation'  => ['nullable', 'date'],
            'lieuConfirmation'  => ['nullable', 'string', 'max:255'],
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
