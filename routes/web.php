<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
