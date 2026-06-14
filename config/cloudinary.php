<?php

/*
 * Cloudinary — photos de profil SignMe.
 * Construit CLOUDINARY_URL depuis CLOUDINARY_API_* si l’URL .env est un placeholder.
 */

$cloudUrl = env('CLOUDINARY_URL');
$apiKey = env('CLOUDINARY_API_KEY', env('CLOUDINARY_KEY'));
$apiSecret = env('CLOUDINARY_API_SECRET', env('CLOUDINARY_SECRET'));
$cloudName = env('CLOUDINARY_CLOUD_NAME');

$isPlaceholder = empty($cloudUrl)
    || str_contains((string) $cloudUrl, '<your')
    || str_contains((string) $cloudUrl, 'YOUR_');

if ($isPlaceholder && $apiKey && $apiSecret && $cloudName) {
    $cloudUrl = sprintf(
        'cloudinary://%s:%s@%s',
        rawurlencode((string) $apiKey),
        rawurlencode((string) $apiSecret),
        $cloudName
    );
}

return [

    'notification_url' => env('CLOUDINARY_NOTIFICATION_URL'),

    'cloud_url' => $cloudUrl,

    'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET'),

    'upload_route' => env('CLOUDINARY_UPLOAD_ROUTE'),

    'upload_action' => env('CLOUDINARY_UPLOAD_ACTION'),
];
