<?php

namespace App\Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class WebhookRequest
 * 
 * Validation pour les webhooks Campay
 */
class WebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Webhooks peuvent être publics
    }

    public function rules(): array
    {
        return [
            'reference' => 'required|string',
            'status' => 'required|string',
        ];
    }
}
