<?php
// Test de connexion locale

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => 'http://localhost:8000/api/v1/health',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);

echo "📍 Test du serveur local...\n";

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);

curl_close($curl);

echo "HTTP Code: $httpCode\n";
if ($response) {
    echo "Response: $response\n";
}
if ($error) {
    echo "Error: $error\n";
}

if ($httpCode === 200) {
    echo "\n✅ Serveur OK! Passons au test du webhook...\n";
} else {
    echo "\n❌ Serveur pas accessible!\n";
    echo "Assurez-vous que Laravel est en cours d'exécution:\n";
    echo "   php artisan serve\n";
}
