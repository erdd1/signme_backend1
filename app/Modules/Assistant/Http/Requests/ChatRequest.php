<?php

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = config('assistant.max_message_chars');

        return [
            'messages'           => ['required', 'array', 'min:1', 'max:50'],
            'messages.*.role'    => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'min:1', "max:{$max}"],
        ];
    }

    public function messages(): array
    {
        return [
            'messages.required'           => 'Posez une question à l\'assistant.',
            'messages.*.role.in'          => 'Rôle de message invalide.',
            'messages.*.content.required' => 'Le message ne peut pas être vide.',
            'messages.*.content.max'      => 'Votre message est trop long.',
        ];
    }

    /** Le dernier message doit être une question de l'utilisateur. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if (collect($this->input('messages'))->last()['role'] !== 'user') {
                $v->errors()->add('messages', 'Le dernier message doit être une question.');
            }
        });
    }
}
