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
    'allowed_origins'          => [
        'http://192.168.1.112:8000',           // Backend local
        'http://127.0.0.1:8000',               // Localhost
        'http://localhost:8000',               // Localhost alt
        'http://localhost:3000',               // Frontend dev (if applicable)
        'http://192.168.1.112:*',              // All ports on local machine
        '*',                                    // Allow all (for development)
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => [],
    'max_age'                  => 0,
    'supports_credentials'     => true,

];
