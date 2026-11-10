<?php

/**
 * Module Assistant religieux — chatbot qui répond aux questions de foi.
 *
 * Aucune conversation n'est stockée : l'historique reste dans l'application
 * et est renvoyé à chaque question. Les journaux ne contiennent jamais le
 * contenu des messages.
 *
 * Le moteur est n'importe quel service au format « chat/completions »
 * (Groq, Gemini, Mistral, OpenAI…) : changer de moteur = changer ces
 * variables d'environnement, sans toucher au code.
 * ASSISTANT_ENABLED=false désactive le module sur-le-champ (réponse 503).
 */
return [

    'enabled' => (bool) env('ASSISTANT_ENABLED', true),

    'base_url' => env('ASSISTANT_API_URL', 'https://api.groq.com/openai/v1'),
    'api_key'  => env('ASSISTANT_API_KEY'),
    'model'    => env('ASSISTANT_MODEL', 'openai/gpt-oss-120b'),

    'timeout'     => (int) env('ASSISTANT_TIMEOUT', 30),
    'max_tokens'  => (int) env('ASSISTANT_MAX_TOKENS', 700),
    'temperature' => (float) env('ASSISTANT_TEMPERATURE', 0.4),

    // Taille d'une conversation acceptée : au-delà, seuls les derniers
    // messages sont transmis au moteur.
    'max_messages'      => (int) env('ASSISTANT_MAX_MESSAGES', 12),
    'max_message_chars' => (int) env('ASSISTANT_MAX_MESSAGE_CHARS', 2000),

    // Limites : rafale par minute, quota quotidien par utilisateur, et
    // plafond quotidien global (protège le quota gratuit / le budget).
    'rate_limit_per_minute' => (int) env('ASSISTANT_RATE_LIMIT', 10),
    'daily_limit_per_user'  => (int) env('ASSISTANT_DAILY_LIMIT_USER', 30),
    'daily_limit_global'    => (int) env('ASSISTANT_DAILY_LIMIT_GLOBAL', 800),

];
