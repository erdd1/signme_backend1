<?php

namespace App\Core\Services;

/**
 * Liste fixe des modules admin pouvant être accordés à un mini-admin via
 * `admin_permissions`. Source unique de vérité — utilisée par le
 * middleware `permission:<resource>` (validation de la clé), par
 * AdminAccessController (validation des payloads) et exposée telle quelle
 * au frontend pour construire la grille de permissions.
 *
 * Le module "Système" (sauvegardes, restauration, mode maintenance, logs)
 * est volontairement ABSENT de cette liste : il reste réservé au super
 * admin exclusivement (middleware `super.admin`), jamais accordable.
 */
class AdminResourceRegistry
{
    public const RESOURCES = [
        'users' => 'Utilisateurs',
        'lookups' => 'Groupes / Quartiers / Villes',
        'churches' => 'Églises',
        'about_church' => "À propos de l'église",
        'signatures' => 'Cartes de signature',
        'anciens_designations' => "Désignations d'anciens",
        'publications' => 'Publications',
        'evenements' => 'Événements & contributions',
        'sorties_financieres' => 'Sorties financières',
        'celebrations' => 'Célébrations',
        'payments' => 'Paiements',
        'rapports' => 'Rapports',
    ];

    public static function keys(): array
    {
        return array_keys(self::RESOURCES);
    }

    public static function isValid(string $resource): bool
    {
        return array_key_exists($resource, self::RESOURCES);
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function toArray(): array
    {
        return array_map(
            fn (string $key, string $label) => ['key' => $key, 'label' => $label],
            array_keys(self::RESOURCES),
            array_values(self::RESOURCES),
        );
    }
}
