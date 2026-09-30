<?php
namespace App\Modules\Tresorerie\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSortieSimpleRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'montant'     => 'nullable|integer|min:100',
            'description' => 'nullable|string|max:255',
            'reference'   => 'nullable|string|max:255',
        ];
    }
}
