<?php
/**
 * Test direct de l'API /api/v1/users
 * Run: php test_api_users.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Start Laravel
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== TESTING API ENDPOINT ===\n\n";

// 1. Get a pasteur user
$pasteur = User::where('role', 'pasteur')->where('churchId', 1)->first();
if (!$pasteur) {
    echo "ERROR: No pasteur found\n";
    exit(1);
}

echo "Using pasteur: {$pasteur->nom} (ID: {$pasteur->id})\n\n";

// 2. Create a token for this user
echo "Creating token...\n";
$token = $pasteur->createToken('test')->plainTextToken;
echo "Token: " . substr($token, 0, 20) . "...\n\n";

// 3. Simulate the API request
echo "Simulating GET /api/v1/users (page=1, per_page=50)...\n";

// Create a fake request
$request = new \Illuminate\Http\Request();
$request->setMethod('GET');
$request->query->add(['page' => 1, 'per_page' => 50]);
$request->setUserResolver(function() use ($pasteur) {
    return $pasteur;
});

// Get the controller
$controller = new \App\Modules\User\Http\UserController(
    app(\App\Modules\User\Application\Services\UserService::class)
);

// Call the index method
echo "Calling controller...\n";
$response = $controller->index($request);
$data = json_decode($response->getContent(), true);

echo "\nAPI Response:\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Clean token
$pasteur->tokens()->delete();

echo "\nDone.\n";
