<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lien de réinitialisation mot de passe (application mobile SignMe)
    |--------------------------------------------------------------------------
    |
    | Deep link ouvert par l'app : signme://reset-password?token=...&email=...
    | Adapter selon votre schéma Android/iOS (intent-filter / universal links).
    |
    */
    'password_reset_deep_link' => env(
        'SIGNME_PASSWORD_RESET_DEEP_LINK',
        'signme://reset-password'
    ),

    /*
    | URL web de secours (page hébergée qui redirige vers l'app ou affiche les instructions).
    */
    'password_reset_web_url' => env(
        'SIGNME_PASSWORD_RESET_WEB_URL',
        env('APP_URL', 'http://localhost') . '/reset-password'
    ),

];
