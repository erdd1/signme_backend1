<?php

namespace App\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UploadUserPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // 2 Mo max (évite les fichiers trop lourds côté mobile)
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required'   => 'Veuillez sélectionner une photo.',
            'photo.image'      => 'Le fichier doit être une image.',
            'photo.mimes'      => 'Formats acceptés : JPG, PNG ou WebP.',
            'photo.max'        => 'La photo ne doit pas dépasser 2 Mo.',
            'photo.dimensions' => 'Image trop grande (max. 4096×4096 px).',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Photo invalide',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
