<?php

require 'vendor/autoload.php';

$client = new GuzzleHttp\Client();
$payload = [
    'from' => '237699803165',
    'amount' => 25,
    'currency' => 'XAF',
    'description' => 'TEST PAYMENT',
    'externalTransactionId' => 'TEST-' . time(),
    'notify_url' => 'https://nonrealizable-brendon-limbless.ngrok-free.dev/api/v1/webhooks/campay',
];

echo "PAYLOAD:\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

try {
    $response = $client->post('https://demo.campay.net/api/collect/', [
        'headers' => [
            'Authorization' => 'Token d95afded1418893729bc7656f650184f46a66f31',
            'Content-Type' => 'application/json',
        ],
        'json' => $payload,
    ]);
    
    echo "SUCCESS (200):\n";
    echo $response->getBody() . "\n";
} catch (GuzzleHttp\Exception\ClientException $e) {
    echo "ERROR (" . $e->getResponse()->getStatusCode() . "):\n";
    echo $e->getResponse()->getBody() . "\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

