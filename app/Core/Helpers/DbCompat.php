<?php

namespace App\Core\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * DbCompat — petites différences de comportement entre moteurs de base de
 * données (MySQL / PostgreSQL) à gérer explicitement plutôt que de laisser
 * un comportement implicite diverger silencieusement entre environnements.
 */
class DbCompat
{
    /**
     * Opérateur LIKE insensible à la casse, portable entre moteurs.
     *
     * MySQL est insensible à la casse par défaut (collation utf8mb4_unicode_ci)
     * pour `LIKE` — PostgreSQL est sensible à la casse par défaut et nécessite
     * `ILIKE` pour le même comportement. Utiliser cet opérateur préserve le
     * comportement de recherche existant (ex: recherche membre par nom/email)
     * quel que soit le moteur configuré.
     */
    public static function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Quote un identifiant de colonne pour l'utiliser dans du SQL brut
     * (DB::raw / selectRaw / whereRaw…). Nécessaire pour toute colonne au
     * nommage camelCase (ex: montantTotal, signatureType, userId) : MySQL
     * est insensible à la casse pour un identifiant non quoté, mais
     * PostgreSQL replie un identifiant non quoté en minuscules — sans
     * quotes, `montantTotal` devient `montanttotal`, qui ne correspond à
     * aucune colonne réelle (« column does not exist »). Backticks pour
     * MySQL, guillemets doubles pour PostgreSQL — jamais l'inverse : MySQL
     * n'accepte les guillemets doubles comme délimiteur d'identifiant que
     * si ANSI_QUOTES est activé (pas le cas par défaut).
     */
    public static function quoteColumn(string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? "\"$column\"" : "`$column`";
    }
}
