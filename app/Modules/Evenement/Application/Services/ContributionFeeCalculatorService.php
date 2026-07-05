<?php

namespace App\Modules\Evenement\Application\Services;

use App\Core\Exceptions\ApiException;

/**
 * Calculateur de frais pour les contributions aux événements (module crowdfunding).
 *
 * Grille de frais (distincte de App\Core\Helpers\FeeCalculator, qui concerne les
 * signatures) :
 *
 *   500     - 950     -> 50 FCFA
 *   1000    - 5000    -> 100 FCFA
 *   5050    - 10000   -> 200 FCFA
 *   10050   - 50000   -> 300 FCFA
 *   50050   - 99950   -> 400 FCFA
 *   100000  - 999950  -> 400 x premier chiffre du montant (ex: 560000 -> 400x5 = 2000)
 *   1000000            -> 10000 FCFA
 *
 * Le frontend Flutter réplique exactement cette même grille pour l'affichage
 * instantané, mais le backend recalcule toujours frais + total : jamais confiance
 * au montant/frais envoyé par le client.
 */
class ContributionFeeCalculatorService
{
    public const MONTANT_MIN = 500;
    public const MONTANT_MAX = 1_000_000;
    public const MULTIPLE_DE = 50;

    // TODO(TEST TEMPORAIRE) : montant de 15 FCFA (frais 5 FCFA) ajouté
    // uniquement pour tester le flux de paiement CamPay sans débiter de
    // grosses sommes. À SUPPRIMER avant mise en production définitive —
    // supprimer cette constante ainsi que les 2 blocs marqués
    // "TODO(TEST TEMPORAIRE)" dans isValidAmount() et calculate() ci-dessous.
    private const MONTANT_TEST_TEMPORAIRE = 15;

    /**
     * Vérifie qu'un montant respecte les bornes et le pas autorisés.
     */
    public static function isValidAmount(int $montant): bool
    {
        // TODO(TEST TEMPORAIRE) : à supprimer.
        if ($montant === self::MONTANT_TEST_TEMPORAIRE) {
            return true;
        }

        return $montant >= self::MONTANT_MIN
            && $montant <= self::MONTANT_MAX
            && $montant % self::MULTIPLE_DE === 0;
    }

    /**
     * Calcule les frais applicables à un montant de contribution.
     *
     * @throws ApiException si le montant est hors barème
     */
    public static function calculate(int $montant): int
    {
        if (!self::isValidAmount($montant)) {
            throw ApiException::badRequest(
                'Montant invalide : doit être compris entre '
                . number_format(self::MONTANT_MIN, 0, ',', ' ') . ' et '
                . number_format(self::MONTANT_MAX, 0, ',', ' ')
                . ' FCFA, par multiples de ' . self::MULTIPLE_DE . '.'
            );
        }

        // TODO(TEST TEMPORAIRE) : à supprimer.
        if ($montant === self::MONTANT_TEST_TEMPORAIRE) {
            return 5;
        }

        return match (true) {
            $montant >= 500 && $montant <= 950 => 50,
            $montant >= 1000 && $montant <= 5000 => 100,
            $montant >= 5050 && $montant <= 10000 => 200,
            $montant >= 10050 && $montant <= 50000 => 300,
            $montant >= 50050 && $montant <= 99950 => 400,
            $montant >= 100000 && $montant <= 999950 => 400 * (int) substr((string) $montant, 0, 1),
            $montant === self::MONTANT_MAX => 10000,
            default => throw ApiException::badRequest('Montant hors barème.'),
        };
    }

    /**
     * Calcule contribution + frais + total à débiter.
     *
     * @return array{montant: int, frais: int, total: int}
     */
    public static function calculateTotal(int $montant): array
    {
        $frais = self::calculate($montant);

        return [
            'montant' => $montant,
            'frais'   => $frais,
            'total'   => $montant + $frais,
        ];
    }
}
