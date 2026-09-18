<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        // Copie locale des sauvegardes de base de données (rétention courte,
        // restauration rapide). Le disque "backups" ci-dessous est la copie
        // hors-site (S3-compatible) qui assure la survie des données même en
        // cas de perte totale du serveur.
        'backups-local' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'throw' => false,
        ],

        // Stockage hors-site des sauvegardes — compatible avec tout
        // fournisseur S3 (AWS S3, Contabo Object Storage, Backblaze B2...)
        // via `endpoint`/`use_path_style_endpoint`. Volontairement distinct
        // du disque "s3" générique ci-dessus pour ne pas mélanger les
        // identifiants des sauvegardes avec ceux d'un usage applicatif futur.
        'backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_ACCESS_KEY_ID'),
            'secret' => env('BACKUP_S3_SECRET_ACCESS_KEY'),
            'region' => env('BACKUP_S3_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'url' => env('BACKUP_S3_URL'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('BACKUP_S3_USE_PATH_STYLE_ENDPOINT', true),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
