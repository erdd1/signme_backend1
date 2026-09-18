<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Binaires utilisés pour dump/restore
    |--------------------------------------------------------------------------
    |
    | En production (Linux/Contabo) les binaires sont normalement déjà sur le
    | PATH — laisser la valeur par défaut suffit. En développement local sous
    | Windows, où le PATH n'est pas toujours configuré, on peut pointer vers
    | le chemin complet de l'installation PostgreSQL/MySQL via les variables
    | d'environnement ci-dessous.
    |
    */

    'pgsql' => [
        'pg_dump' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),
        'pg_restore' => env('BACKUP_PG_RESTORE_PATH', 'pg_restore'),
        'psql' => env('BACKUP_PSQL_PATH', 'psql'),
    ],

    'mysql' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL_PATH', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Disques (voir config/filesystems.php)
    |--------------------------------------------------------------------------
    */

    'local_disk' => 'backups-local',
    'offsite_disk' => 'backups',

    // Nombre de jours de rétention avant suppression automatique (local ET
    // hors-site) des anciennes sauvegardes.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    // Délai maximum (secondes) accordé au dump/restore avant timeout — une
    // base de données qui grossit avec le temps peut dépasser la valeur par
    // défaut de Symfony Process (60s).
    'process_timeout' => (int) env('BACKUP_PROCESS_TIMEOUT', 1800),

];
