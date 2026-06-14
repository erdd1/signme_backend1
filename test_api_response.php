<?php
/**
 * Script pour tester la réponse HTTP brute de l'API
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Route;

echo "=== TEST API HTTP ===\n\n";

// Créer une requête fake
$user = User::find(2); // yvan

// Simuler une requête GET
$request = new \Illuminate\Http\Request();
$request->setUserResolver(fn() => $user);
$request->merge(['month' => date('Y-m')]);

// Créer le contrôleur et appeler la méthode
$controller = new \App\Modules\Rapport\Http\RapportController(
    app(\App\Modules\Rapport\Application\Services\RapportService::class)
);

// Appeler la méthode secretaire
$response = $controller->secretaire($request);

echo "Status Code: " . $response->status() . "\n\n";

$json = json_decode($response->getContent(), true);

echo "=== RÉPONSE JSON ===\n";
echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== DATA RETOURNÉE ===\n";
if (isset($json['data'])) {
    echo "data keys: " . implode(', ', array_keys($json['data'])) . "\n\n";
    echo "data: " . json_encode($json['data'], JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== FIN TEST ===\n";
