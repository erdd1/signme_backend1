<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Campay — Configuration Paiement Mobile Money Cameroun
    |--------------------------------------------------------------------------
    |
    | Campay est la passerelle de paiement mobile pour le Cameroun.
    | Elle supporte Orange Money et MTN Mobile Money.
    |
    | Documentation : https://docs.campay.net
    |
    */

    // URL de base de l'API Campay (sans le slash final, il est ajouté dynamiquement)
    'base_url' => env('CAMPAY_BASE_URL', 'https://demo.campay.net/api'),

    // ── Authentification Campay (2 modes selon l'intégration) ──────────────
    // Mode 1 : Token API statique (recommandé en production)
    'api_key'      => env('CAMPAY_API_KEY'),

    // Mode 2 : Username/Password pour obtenir un token temporaire
    'app_username' => env('CAMPAY_APP_USERNAME'),
    'app_password' => env('CAMPAY_APP_PASSWORD'),

    // Secret pour valider la signature HMAC des webhooks entrants
    'webhook_secret' => env('CAMPAY_WEBHOOK_SECRET'),

    // URL du webhook Laravel (Campay appelle cette URL après paiement)
    'webhook_url' => env('CAMPAY_WEBHOOK_URL'),

    // Environnement : 'demo' ou 'production'
    'environment' => env('CAMPAY_ENVIRONMENT', 'demo'),

    // Délai maximum pour qu'un client confirme son paiement (en secondes)
    'payment_timeout' => 60,

    // Devise (toujours XAF pour le Cameroun)
    'currency' => 'XAF',

    // Opérateurs supportés
    'operators' => [
        'ORANGE MONEY'     => 'Orange Money Cameroun',
        'MTN MOBILE MONEY' => 'MTN Mobile Money Cameroun',
    ],
];
