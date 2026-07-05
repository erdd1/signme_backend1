<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure CORS settings for your application. This
    | configuration is used by the HandleCors middleware provided by
    | Laravel to facilitate cross-origin HTTP requests.
    |
    */

    'paths'                    => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods'          => ['*'],
    // Liste blanche des origines autorisées, pilotée par CORS_ALLOWED_ORIGINS
    // (valeurs séparées par des virgules). En production, définir cette
    // variable avec le(s) domaine(s) réel(s) du frontend — jamais de '*'
    // combiné à supports_credentials=true (reflète alors n'importe quelle
    // origine avec les identifiants, ce qui annule la protection CORS).
    'allowed_origins'          => array_values(array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))
    )),
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => [],
    'max_age'                  => 0,
    'supports_credentials'     => true,

];
