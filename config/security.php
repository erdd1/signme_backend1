<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TOKEN EXPIRATION
    |--------------------------------------------------------------------------
    | Durée de validité des tokens JWT
    */
    'tokens' => [
        // Access token court (15-30 min)
        'access_token_expires_in_minutes'  => 30,

        // Refresh token long (7-30 jours)
        'refresh_token_expires_in_days'    => 7,

        // Remember token (60 jours)
        'remember_token_expires_in_days'   => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | RATE LIMITING
    |--------------------------------------------------------------------------
    | Limiter les tentatives pour éviter brute force
    */
    'rate_limiting' => [
        // Login : max 5 tentatives par 15 min (par email + IP)
        'login' => [
            'max_attempts'    => 5,
            'decay_minutes'   => 15,
        ],

        // Register : max 3 par 60 min (par IP)
        'register' => [
            'max_attempts'    => 3,
            'decay_minutes'   => 60,
        ],

        // OTP/MFA : max 10 par 10 min (par user)
        'otp' => [
            'max_attempts'    => 10,
            'decay_minutes'   => 10,
        ],

        // Mot de passe oublié : max 3 par 60 min (par email + IP)
        'forgot_password' => [
            'max_attempts'    => 3,
            'decay_minutes'   => 60,
        ],

        // Reset Password : max 5 par 60 min (par email)
        'reset_password' => [
            'max_attempts'    => 5,
            'decay_minutes'   => 60,
        ],

        // Refresh Token : max 20 par 60 min (par user)
        'refresh_token' => [
            'max_attempts'    => 20,
            'decay_minutes'   => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TEMPORARY BLOCKING
    |--------------------------------------------------------------------------
    | Bloquer après trop d'essais échoués
    */
    'blocking' => [
        // Après N échecs → blocage pour X minutes
        'thresholds' => [
            ['failed_count' => 5,  'block_minutes' => 1],    // 5 échecs → 1 min
            ['failed_count' => 10, 'block_minutes' => 15],   // 10 échecs → 15 min
            ['failed_count' => 20, 'block_minutes' => 120],  // 20 échecs → 2h
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | EMAIL VERIFICATION
    |--------------------------------------------------------------------------
    | Vérification email pour sécurité
    */
    'email_verification' => [
        'enabled'           => true,
        'token_expires_in_hours' => 24,
        'require_for_login' => false, // À activer plus tard
        'require_for_password_reset' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTPS & SECURITY HEADERS
    |--------------------------------------------------------------------------
    */
    'https' => [
        'force_https'       => env('APP_FORCE_HTTPS', true),
        'hsts_enabled'      => env('HSTS_ENABLED', true),
        'hsts_max_age'      => 31536000, // 1 year
    ],

    /*
    |--------------------------------------------------------------------------
    | LOGGING SECURITY
    |--------------------------------------------------------------------------
    | Logging des actions sensibles
    */
    'logging' => [
        'enabled'           => true,
        'log_failed_logins' => true,
        'log_password_changes' => true,
        'log_token_refresh' => true,
        'log_email_verification' => true,

        // Données à MASQUER dans les logs
        'sanitize_fields'   => [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SUSPICIOUS ACTIVITY DETECTION (PHASE 2)
    |--------------------------------------------------------------------------
    | Détecter comportements anormaux
    */
    'suspicious_activity' => [
        'enabled'           => true,
        'max_login_per_minute' => 5,        // Plus de 5 logins/min = suspect
        'different_countries_alert' => true, // Alerte si pays différent
        'multiple_ips_alert' => true,        // Alerte si multiples IPs
        'max_sessions_per_user' => 5,        // Max sessions simultanées
        'unusual_hours' => [0, 1, 2, 3, 4, 5], // Heures considérées inhabituelles
    ],

    /*
    |--------------------------------------------------------------------------
    | MFA (Multi-Factor Authentication) (PHASE 2)
    |--------------------------------------------------------------------------
    */
    'mfa' => [
        'enabled'               => true,
        'type'                  => 'email', // email ou totp (future)
        'otp_length'            => 6,
        'otp_expiry_minutes'    => 10,
        'otp_max_attempts'      => 5,
        'backup_codes_count'    => 8,
        'require_for_admin'     => true, // MFA obligatoire pour admins
    ],

    /*
    |--------------------------------------------------------------------------
    | DEVICE MANAGEMENT (PHASE 2)
    |--------------------------------------------------------------------------
    */
    'device_management' => [
        'enabled'               => true,
        'track_devices'         => true,
        'max_active_devices'    => 5,
        'device_session_timeout_hours' => 24,
        'require_device_approval_on_new_ip' => false, // Future enhancement
    ],

    /*
    |--------------------------------------------------------------------------
    | NOTIFICATION SETTINGS (PHASE 2)
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'enabled'                   => true,
        'notify_new_login'          => true,
        'notify_password_change'    => true,
        'notify_new_device'         => true,
        'notify_suspicious_activity' => true,
        'notify_mfa_change'         => true,
    ],
];
