<?php

use App\Modules\Evenement\Http\EvenementShareController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|-----------------------------------------------------------------------
| Partage d'événement (Android App Links / iOS Universal Links)
|-----------------------------------------------------------------------
| Même URL que celle partagée par le pasteur/la secrétaire (WhatsApp, etc.)
| et que celle interceptée par l'app si elle est installée et le domaine
| vérifié. Toujours public (pas d'auth:sanctum) : sert l'aperçu Open Graph
| (WhatsApp/Instagram/LinkedIn/Facebook) et une page de secours pour ceux
| qui n'ont pas encore l'app.
*/
Route::get('/evenements/{uuid}', [EvenementShareController::class, 'show'])
    ->name('evenements.share');

/*
|-----------------------------------------------------------------------
| Association de domaine — Android App Links & iOS Universal Links
|-----------------------------------------------------------------------
| Doivent être servis en HTTPS, sans redirection, Content-Type JSON exact.
| Voir config/services.php ("mobile_app") pour les valeurs.
*/
Route::get('/.well-known/assetlinks.json', function () {
    return response()->json([
        [
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => config('services.mobile_app.android_package_name'),
                'sha256_cert_fingerprints' => array_values(
                    config('services.mobile_app.android_sha256_fingerprints', [])
                ),
            ],
        ],
    ]);
});

Route::get('/.well-known/apple-app-site-association', function () {
    $teamId = config('services.mobile_app.apple_team_id');
    $packageName = config('services.mobile_app.android_package_name');

    return response()->json([
        'applinks' => [
            'apps' => [],
            'details' => [
                [
                    'appID' => "{$teamId}.{$packageName}",
                    'paths' => ['/evenements/*'],
                ],
            ],
        ],
    ]);
});

/*
|-----------------------------------------------------------------------
| Callback paiement carte NotchPay
|-----------------------------------------------------------------------
| Page de retour après un paiement carte via la WebView intégrée à
| l'app. NotchPay redirige le navigateur vers cette URL une fois le
| paiement (réussi ou non) terminé sur sa page hébergée. La WebView
| Flutter détecte la navigation vers cette URL pour se fermer
| automatiquement — le statut réel du paiement provient toujours du
| webhook/polling, jamais de cette page.
*/
Route::get('/payment/callback', function () {
    return response(
        '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Paiement terminé</title></head>'
        . '<body style="font-family: sans-serif; text-align: center; padding-top: 3rem;">'
        . '<h2>Paiement terminé</h2>'
        . '<p>Vous pouvez fermer cette fenêtre et retourner à l\'application.</p>'
        . '</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=UTF-8']
    );
})->name('payment.callback');
