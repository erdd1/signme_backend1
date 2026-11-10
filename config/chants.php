<?php

/**
 * Module Chants — répertoire de chants (paroles complètes, recherche par titre
 * ou par extrait de paroles).
 *
 * Aucune parole n'est persistée en base : seules des copies temporaires
 * (cache, durée limitée) sont gardées pour la rapidité et pour ménager les
 * services tiers. CHANTS_ENABLED=false désactive le module sur-le-champ
 * (réponse 503), par exemple en cas de réclamation d'un ayant droit.
 */
return [

    'enabled' => (bool) env('CHANTS_ENABLED', true),

    // Durée de vie du cache temporaire (secondes). 24 h par défaut.
    'cache_ttl' => (int) env('CHANTS_CACHE_TTL', 86400),

    // Nombre maximal de chants renvoyés par recherche.
    'max_results' => (int) env('CHANTS_MAX_RESULTS', 20),

    // Requêtes par minute et par utilisateur.
    'rate_limit_per_minute' => (int) env('CHANTS_RATE_LIMIT', 30),

    // Source des paroles (LRCLIB : recherche par titre / artiste).
    'lrclib' => [
        'base_url' => env('CHANTS_LRCLIB_URL', 'https://lrclib.net'),
        'timeout'  => (int) env('CHANTS_LRCLIB_TIMEOUT', 8),
    ],

    // Retrouve le titre d'un chant à partir d'un extrait de paroles.
    // Optionnel : sans jeton, seule la recherche par titre est active.
    'genius' => [
        'base_url' => env('CHANTS_GENIUS_URL', 'https://api.genius.com'),
        'token'    => env('CHANTS_GENIUS_TOKEN'),
        'timeout'  => (int) env('CHANTS_GENIUS_TIMEOUT', 6),
    ],

    // Mention affichée sous chaque chant dans l'application.
    'attribution' => [
        'nom' => 'LRCLIB',
        'url' => 'https://lrclib.net',
    ],

];
