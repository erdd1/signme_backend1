<?php
// Lire le .env
$env_file = '.env';
$env = [];
foreach (file($env_file) as $line) {
    $line = trim($line);
    if ($line && strpos($line, '=') !== false && $line[0] !== '#') {
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, '"\'');
    }
}

$secret = $env['CAMPAY_WEBHOOK_SECRET'] ?? '';
$url = $env['CAMPAY_WEBHOOK_URL'] ?? '';

if (!$secret || !$url) {
    die("❌ ERREUR: Secret ou URL manquante!\n");
}

// Payload (réference réelle de Campay)
$payload = [
    'reference' => '846d1e97-57f9-40ab-9216-e44fa9ddbbb4',
    'status' => 'SUCCESSFUL',
    'amount' => '20.00',
    'currency' => 'XAF',
    'operator' => 'Orange',
    'code' => 'D260425D0032NE',
];

$rawBody = json_encode($payload);
$hmac = hash_hmac('sha256', $rawBody, $secret);

// Utiliser l'URL locale pour tester
$testUrl = 'http://localhost:8000/api/v1/webhooks/campay';

echo "🧪 TEST WEBHOOK CAMPAY\n";
echo "════════════════════════════════════════════\n\n";
echo "📍 URL: $testUrl\n";
echo "🔐 HMAC: " . substr($hmac, 0, 30) . "...\n\n";
echo "⏳ Envoi du webhook...\n\n";

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $testUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $rawBody,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Campay-Signature: ' . $hmac,
    ],
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);

curl_close($curl);

echo "✅ Réponse HTTP: $httpCode\n";
if ($response) {
    echo "📋 Body: $response\n";
}
if ($error) {
    echo "❌ Erreur: $error\n";
}

echo "\n════════════════════════════════════════════\n";
echo "✅ Vérifiez les logs:\n";
echo "   Get-Content storage/logs/laravel.log -Tail 30 | Select-String 'Webhook|traité'\n";
echo "════════════════════════════════════════════\n";
