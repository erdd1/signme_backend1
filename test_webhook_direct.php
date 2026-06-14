<?php

require 'vendor/autoload.php';

$client = new GuzzleHttp\Client();

// Configuration
$reference = '3cb87d32-f14d-4c98-8f13-c4074ebd0cfc';
$secret = 'CXe2OMTWfv3wO9DcmCDfffWgWaqicUdCi4vCBsn2w700R9uzYptO5OLiB7lEm7oT3NTmAuGjYK1PA2KNJAJ3BA';

// Payload COMPACT (comme Campay l'enverrait vraiment)
$payload = [
    'reference' => $reference,
    'status' => 'SUCCESSFUL',
];

$rawBody = json_encode($payload);
$hmac = hash_hmac('sha256', $rawBody, $secret);

echo "═══════════════════════════════════════════════════════\n";
echo "TEST DIRECT DU WEBHOOK\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "📦 Payload:\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

echo "🔐 HMAC: {$hmac}\n\n";

try {
    $response = $client->post('http://localhost:8000/api/v1/webhooks/campay', [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Campay-Signature' => $hmac,
        ],
        'body' => $rawBody,
    ]);

    echo "✅ SUCCESS (" . $response->getStatusCode() . "):\n";
    echo $response->getBody() . "\n";

} catch (GuzzleHttp\Exception\ClientException $e) {
    echo "❌ ERROR (" . $e->getResponse()->getStatusCode() . "):\n";
    echo $e->getResponse()->getBody() . "\n";
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════════════\n";
