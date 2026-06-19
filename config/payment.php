<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File d'attente Redis (paiements Campay)
    |--------------------------------------------------------------------------
    */
    'queue' => env('PAYMENT_QUEUE', 'payments'),

    'connection' => env('PAYMENT_QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Polling Campay (jobs différés après initiation)
    |--------------------------------------------------------------------------
    | Délais en secondes — complète le webhook si l'utilisateur confirme tard.
    */
    'polling' => [
        'delays_seconds' => [5, 10, 15, 25, 40, 60, 90, 120, 180],
        'max_attempts'   => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Redis statut transaction (lecture rapide par l'app)
    |--------------------------------------------------------------------------
    */
    'cache_ttl_seconds' => (int) env('PAYMENT_STATUS_CACHE_TTL', 3600),

    'cache_prefix' => 'payment:tx:',

    /*
    |--------------------------------------------------------------------------
    | Campay
    |--------------------------------------------------------------------------
    */
    'campay' => [
        // Désactivable uniquement en local/démo (jamais en production).
        'webhook_verify_signature' => env('CAMPAY_WEBHOOK_VERIFY_SIGNATURE', true),
    ],

];
