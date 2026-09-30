<?php
namespace App\Modules\Tresorerie\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class CreateSortieSimpleRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'montant'     => 'required|integer|min:100',
            'description' => 'required|string|max:255',
            'reference'   => 'nullable|string|max:255',
        ];
    }
    public function messages(): array {
        return [
            'montant.required'     => 'Le montant est obligatoire.',
            'montant.min'          => 'Le montant minimum est 100 FCFA.',
            'description.required' => 'La description est obligatoire.',
        ];
    }
}
