<?php

namespace App\Core\Helpers;

/**
 * PhoneMasker — masque un numéro de téléphone pour l'affichage admin
 * (ex: "237654321098" → "23765****98"). Point d'implémentation unique,
 * réutilisé par PaymentResource et les Resources de détail paiement/
 * contribution — jamais dupliqué pour éviter toute divergence d'affichage.
 */
class PhoneMasker
{
    public static function mask(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        return substr($phone, 0, 5) . '****' . substr($phone, -2);
    }
}
