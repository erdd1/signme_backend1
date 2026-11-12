<?php

/**
 * Chants — l'API principale ne porte plus que l'accès au HUB CHANTS.
 *
 * La recherche, les paroles, les liens de chants, l'historique et les favoris
 * vivent dans le hub (service séparé : autre base, autre conteneur) : une panne
 * ou un pic de recherches ne touche jamais les paiements.
 * CHANTS_ENABLED=false (ou CHANTS_HUB_URL vide) désactive l'accès sur-le-champ
 * (503) : l'application affiche « indisponible », sans mise à jour.
 */
return [

    'enabled' => (bool) env('CHANTS_ENABLED', true),

    // Remises de jeton par minute et par utilisateur.
    'rate_limit_per_minute' => (int) env('CHANTS_RATE_LIMIT', 30),

    // Hub Chants : l'API principale ne fait que délivrer un jeton signé de courte
    // durée, que le hub vérifie seul (clé publique) — aucun appel du hub vers
    // l'API principale. URL vide = hub désactivé.
    'hub' => [
        'url'                  => env('CHANTS_HUB_URL'),
        'jwt_private_key_path' => env('CHANTS_HUB_JWT_PRIVATE_KEY', storage_path('app/keys/chants_jwt_private.pem')),
        'token_ttl_minutes'    => (int) env('CHANTS_HUB_TOKEN_TTL', 15),
        'issuer'               => 'signme-api',
        'audience'             => 'signme-chants',
    ],

];
