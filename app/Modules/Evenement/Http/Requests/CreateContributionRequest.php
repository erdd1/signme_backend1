<?php

namespace App\Modules\Evenement\Http\Requests;

use App\Modules\Evenement\Application\Services\ContributionFeeCalculatorService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant'         => [
                'required',
                'integer',
                // Déléguée à ContributionFeeCalculatorService::isValidAmount() —
                // source unique de vérité partagée avec le calcul des frais
                // (inclut temporairement le montant de test 15 FCFA, cf. TODO
                // dans ContributionFeeCalculatorService).
                function ($attribute, $value, $fail) {
                    if (!ContributionFeeCalculatorService::isValidAmount((int) $value)) {
                        $fail(
                            'Le montant doit être compris entre '
                            . number_format(ContributionFeeCalculatorService::MONTANT_MIN, 0, ',', ' ') . ' et '
                            . number_format(ContributionFeeCalculatorService::MONTANT_MAX, 0, ',', ' ')
                            . ' FCFA, par multiples de ' . ContributionFeeCalculatorService::MULTIPLE_DE . '.'
                        );
                    }
                },
            ],
            'note'            => ['nullable', 'string', 'max:100'],
            // Carte bancaire : le fidèle saisit sa carte sur la page hébergée du
            // fournisseur — ni opérateur ni numéro de téléphone à fournir.
            'paymentMethod'   => ['required', 'string', 'in:mobile_money,card'],
            'paymentOperator' => ['required_if:paymentMethod,mobile_money', 'nullable', 'string', 'in:ORANGE MONEY,MTN MOBILE MONEY'],
            'paymentNumber'   => ['required_if:paymentMethod,mobile_money', 'nullable', 'string', 'regex:/^(?:6[0-9]{8}|237[0-9]{9})$/'],
            // Obligatoire seulement si l'utilisateur appartient à plusieurs
            // groupes (vérifié dans ContributionService, pas ici : la règle
            // dépend du nombre de groupes de l'utilisateur connecté).
            'groupeId'        => ['nullable', 'integer', 'exists:groupes,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'montant.required'       => 'Le montant de la contribution est obligatoire.',
            'note.max'               => 'La note ne peut pas dépasser 100 caractères.',
            'paymentMethod.required' => 'La méthode de paiement est obligatoire.',
            'paymentMethod.in'       => 'Méthode de paiement invalide. Utilisez : mobile_money ou card.',
            'paymentOperator.required_if' => 'L\'opérateur mobile money est obligatoire.',
            'paymentOperator.in'     => 'Opérateur invalide. Utilisez : ORANGE MONEY ou MTN MOBILE MONEY.',
            'paymentNumber.required_if' => 'Le numéro de téléphone est obligatoire.',
            'paymentNumber.regex'    => 'Numéro invalide. Format attendu : 237699803165 ou 699803165.',
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
