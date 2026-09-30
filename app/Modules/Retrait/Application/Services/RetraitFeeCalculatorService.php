<?php

namespace App\Modules\Retrait\Application\Services;

/**
 * Calcul des frais retenus sur un retrait.
 *
 * ⚠ NotchPay n'expose aucune grille de frais pour les Transfer (payout) dans
 * son OpenAPI/SDK public (contrairement aux frais de Payment, gérés par
 * ContributionFeeCalculatorService/FeeCalculator) : le taux réel dépend du
 * contrat marchand NotchPay et n'est pas interrogeable par API. Le taux
 * ci-dessous est donc configurable (`payment.notchpay.transfer_fee_percent`)
 * et doit être ajusté avec la valeur réelle communiquée par NotchPay avant
 * mise en production — voir .env `NOTCHPAY_TRANSFER_FEE_PERCENT`.
 */
class RetraitFeeCalculatorService
{
    /**
     * Calcule frais + montant net pour un retrait de `montantDemande` FCFA
     * (montant déduit du solde interne de l'église).
     *
     * @return array{montant_demande: int, frais: int, montant_net: int}
     */
    public static function calculate(int $montantDemande): array
    {
        $pourcentage = (float) config('payment.notchpay.transfer_fee_percent', 0);
        $frais = (int) round($montantDemande * $pourcentage / 100);

        return [
            'montant_demande' => $montantDemande,
            'frais' => $frais,
            'montant_net' => $montantDemande - $frais,
        ];
    }
}
