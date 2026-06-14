<?php
/**
 * Test webhook Campay en direct — simule ce que Campay envoie après paiement.
 *
 * Usage : php test_webhook_live.php [reference] [status]
 * Exemple: php test_webhook_live.php 3d7b2b27-9c7b-4cfe-86cc-45dc3a830175 SUCCESSFUL
 *
 * Ce script envoie la requête directement à localhost (pas besoin de ngrok).
 */

$reference = $argv[1] ?? '3d7b2b27-9c7b-4cfe-86cc-45dc3a830175';
$status    = $argv[2] ?? 'SUCCESSFUL';
$secret    = 'CXe2OMTWfv3wO9DcmCDfffWgWaqicUdCi4vCBsn2w700R9uzYptO5OLiB7lEm7oT3NTmAuGjYK1PA2KNJAJ3BA';
$webhookUrl = 'http://127.0.0.1:8000/api/v1/webhooks/campay';

// Payload exactement comme Campay l'envoie
$payload = json_encode([
    'reference' => $reference,
    'status'    => $status,
    'amount'    => '1000',
    'currency'  => 'XAF',
    'operator'  => 'ORANGE',
]);

// HMAC-SHA256 signé avec le secret webhook
$hmac = hash_hmac('sha256', $payload, $secret);

echo "\n══════════════════════════════════════════════════════\n";
echo "  WEBHOOK LIVE TEST — Simulation Campay\n";
echo "══════════════════════════════════════════════════════\n";
echo "  URL       : {$webhookUrl}\n";
echo "  Reference : {$reference}\n";
echo "  Status    : {$status}\n";
echo "  HMAC      : {$hmac}\n";
echo "══════════════════════════════════════════════════════\n\n";

// Envoi de la requête via cURL
$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Campay-Signature: ' . $hmac,
    ],
    CURLOPT_TIMEOUT        => 10,
]);

$response   = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "❌ ERREUR cURL : {$curlError}\n";
    echo "   → Vérifiez que le serveur Laravel tourne : php artisan serve\n";
} else {
    echo "📡 Réponse HTTP : {$httpCode}\n";
    echo "📦 Body         : {$response}\n\n";

    if ($httpCode === 200) {
        echo "✅ Webhook reçu avec succès !\n";
        echo "   Vérifiez les logs Laravel (storage/logs/laravel.log)\n";
        echo "   et l'état de la signature en BD.\n";
    } else {
        echo "⚠️  Code inattendu : {$httpCode}\n";
    }
}

echo "\n══════════════════════════════════════════════════════\n";
