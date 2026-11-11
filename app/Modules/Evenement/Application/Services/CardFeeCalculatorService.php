<?php

namespace App\Modules\Evenement\Application\Services;

use App\Core\Exceptions\ApiException;

/**
 * Frais du paiement par carte (contributions, dons et signatures).
 *
 * Le fournisseur retient un pourcentage du total débité (config
 * payment.card.fee_percent). Pour que l'église reçoive exactement le montant
 * prévu, le total est majoré en conséquence : total = montant / (1 - taux),
 * arrondi au FCFA supérieur. Les bornes du montant sont celles des
 * contributions Mobile Money ; le serveur recalcule toujours frais et total.
 */
class CardFeeCalculatorService
{
    /**
     * Frais d'une contribution ou d'un don : mêmes bornes de montant que les
     * contributions Mobile Money.
     *
     * @return array{montant: int, frais: int, total: int}
     *
     * @throws ApiException
     */
    public static function calculateTotal(int $montant, ?float $percent = null): array
    {
        if (!ContributionFeeCalculatorService::isValidAmount($montant)) {
            throw ApiException::badRequest('Montant invalide.');
        }

        return self::majorer($montant, $percent);
    }

    /**
     * Majoration pour un montant net quelconque (ex. total d'une signature de
     * plusieurs mois) : le total est celui qui, après la retenue du fournisseur,
     * laisse exactement le montant net.
     *
     * @return array{montant: int, frais: int, total: int}
     *
     * @throws ApiException
     */
    public static function majorer(int $montantNet, ?float $percent = null): array
    {
        if ($montantNet <= 0) {
            throw ApiException::badRequest('Montant invalide.');
        }

        $percent ??= (float) config('payment.card.fee_percent');
        if ($percent < 0 || $percent >= 100) {
            throw ApiException::serviceUnavailable('Frais de carte mal configurés.');
        }

        // round() avant ceil() : évite qu'une erreur de virgule flottante
        // (ex. 1000.0000000000001) ajoute 1 FCFA de trop.
        $total = (int) ceil(round($montantNet / (1 - $percent / 100), 6));

        return ['montant' => $montantNet, 'frais' => $total - $montantNet, 'total' => $total];
    }
}
