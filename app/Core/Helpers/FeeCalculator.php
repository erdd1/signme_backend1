<?php

namespace App\Core\Helpers;

/**
 * Calculateur de frais de service pour les signatures à distance.
 *
 * Règles métier EEC SignCard :
 *  - 500 ≤ montant ≤ 1000   → frais = 50 FCFA
 *  - 1500 ≤ montant ≤ 4500  → frais = 100 FCFA
 *  - 5000 ≤ montant ≤ 9500  → frais = 150 FCFA
 *  - montant ≥ 10000         → frais = 200 FCFA
 *  - présentiel              → frais = 0
 *
 * @package App\Core\Helpers
 */
class FeeCalculator
{
    /**
     * Montants d'offrandes autorisés (FCFA)
     */
    public const ALLOWED_AMOUNTS = [15, 500, 1000, 1500, 2000, 2500, 3000, 4000, 5000, 10000];

    /**
     * Montants suggérés pour le présentiel (plus flexibles)
     */
    public const SUGGESTED_AMOUNTS_PRESENTIEL = [15, 500, 1000, 2000, 5000, 10000, 20000, 50000];

    /**
     * Calcule les frais de service pour une signature à distance.
     *
     * @param int $amount Montant de l'offrande en FCFA
     * @return int Frais en FCFA
     */
    public static function calculate(int $amount): int
    {
        return match (true) {
            $amount >= 15                       => 5,   // test Montants très faibles (ex: 15 FCFA) sont gratuits
            $amount >= 500  && $amount <= 1000  => 50,
            $amount >= 1500 && $amount <= 4500  => 100,
            $amount >= 5000 && $amount <= 9500  => 150,
            $amount >= 10000                    => 200,
            default                             => 0,
        };
    }

    /**
     * Calcule le montant total à débiter (offrande + frais)
     *
     * @param int $amount Montant de l'offrande
     * @param int $nbMonths Nombre de mois à signer
     * @return array{amount: int, fees: int, total: int, months: int}
     */
    public static function calculateTotal(int $amount, int $nbMonths = 1): array
    {
        $fees  = self::calculate($amount);
        $total = ($amount + $fees) * $nbMonths;

        return [
            'amount' => $amount,
            'fees'   => $fees,
            'total'  => $total,
            'months' => $nbMonths,
        ];
    }

    /**
     * Vérifie si un montant est valide pour une signature à distance.
     *
     * @param int $amount
     * @return bool
     */
    public static function isValidDistanceAmount(int $amount): bool
    {
        return in_array($amount, self::ALLOWED_AMOUNTS, true);
    }

    /**
     * Retourne la grille complète des frais (utile pour la documentation / réponse API)
     *
     * @return array
     */
    public static function getFeeGrid(): array
    {
        $grid = [];
        foreach (self::ALLOWED_AMOUNTS as $amount) {
            $fees    = self::calculate($amount);
            $grid[]  = [
                'amount'      => $amount,
                'fees'        => $fees,
                'total'       => $amount + $fees,
                'amount_label' => number_format($amount, 0, ',', ' ') . ' FCFA',
                'fees_label'   => number_format($fees, 0, ',', ' ') . ' FCFA',
            ];
        }
        return $grid;
    }
}
