<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    'notchpay' => [
        'public_key' => env('NOTCHPAY_PUBLIC_KEY'),
        'private_key' => env('NOTCHPAY_PRIVATE_KEY'),
        'webhook_hash' => env('NOTCHPAY_WEBHOOK_HASH'),
        'callback_url' => env('NOTCHPAY_CALLBACK_URL'),
    ],

    // Client OAuth "Web" créé dans Google Cloud Console — c'est l'audience
    // (aud) attendue dans l'ID token transmis par l'app mobile via
    // google_sign_in (serverClientId).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    // Numéro WhatsApp du service client — servi au frontend via
    // GET /support/whatsapp plutôt que codé en dur côté app (modifiable
    // sans nouvelle publication de l'application).
    'whatsapp' => [
        'support_number' => env('WHATSAPP_SUPPORT_NUMBER'),
    ],

    // Notifications push (Firebase Cloud Messaging). Clé de service jamais
    // committée — voir storage/app/private/firebase (exclu de Git).
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id'  => env('FIREBASE_PROJECT_ID'),
    ],

];
