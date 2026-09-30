<?php
namespace App\Modules\Tresorerie\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class RejectSortieRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'motif' => 'required|string|max:500',
        ];
    }
    public function messages(): array {
        return [
            'motif.required' => 'Le motif de rejet est obligatoire.',
            'motif.max'      => 'Le motif de rejet ne peut pas dépasser 500 caractères.',
        ];
    }
}
