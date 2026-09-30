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

    // Liens vers l'app mobile (partage d'événements, Android App Links,
    // iOS Universal Links) — voir routes/web.php pour /.well-known/*.
    'mobile_app' => [
        'android_package_name' => env('ANDROID_PACKAGE_NAME', 'com.signmecy.mobile'),

        // Empreintes SHA-256 (une par certificat de signature) autorisées à
        // ouvrir les liens https://<domaine>/evenements/* directement dans
        // l'app Android — assetlinks.json en accepte plusieurs, séparées par
        // une virgule. Ajouter celle du keystore de RELEASE dès qu'il existe
        // (ne jamais retirer celle de debug tant que des builds de test
        // l'utilisent encore).
        'android_sha256_fingerprints' => array_filter(array_map(
            'trim',
            explode(',', env(
                'ANDROID_SHA256_FINGERPRINTS',
                '0C:51:12:1F:3B:F7:DB:5A:5A:77:A7:A8:69:37:81:34:1C:B7:18:5C:83:2A:E8:51:1D:03:D8:F6:2E:78:2C:61'
            ))
        )),

        // Team ID Apple Developer (ex: "A1B2C3D4E5") — indispensable pour que
        // apple-app-site-association soit valide. Tant que le compte
        // développeur n'existe pas, cette valeur reste un placeholder et les
        // Universal Links iOS ne peuvent pas être vérifiés par Apple.
        'apple_team_id' => env('APPLE_TEAM_ID', 'TEAMID_A_REMPLACER'),

        // ID numérique App Store (visible dans l'URL App Store Connect une
        // fois l'app créée) — utilisé pour le bouton "Télécharger" de la page
        // de partage tant que l'app n'est pas publiée sur Android/iOS.
        'play_store_url' => env(
            'PLAY_STORE_URL',
            'https://play.google.com/store/apps/details?id=' . env('ANDROID_PACKAGE_NAME', 'com.signmecy.mobile')
        ),
        'app_store_url' => env('APP_STORE_URL'),
    ],

];
