<?php
/**
 * Test script to simulate the exact Flutter/Dio request
 * Tests: POST /api/v1/auth/login → GET /api/v1/users
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';

use App\Models\User;
use Illuminate\Support\Facades\Http;

// Start Laravel
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== SIMULATING FLUTTER HTTP REQUEST ===\n\n";

// 1. Login as Azerty
echo "1. Testing login...\n";
$loginResponse = Http::post('http://localhost:8000/api/v1/auth/login', [
    'email'    => 'azerty@gmail.com',
    'password' => 'azerty123',
]);

echo "Login Status: " . $loginResponse->status() . "\n";

if ($loginResponse->failed()) {
    echo "ERROR: Login failed\n";
    echo $loginResponse->body() . "\n";
    exit(1);
}

$loginData = $loginResponse->json();
$token = $loginData['data']['access_token'] ?? null;

if (!$token) {
    echo "ERROR: No token in response\n";
    echo json_encode($loginData, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

echo "Token: " . substr($token, 0, 20) . "...\n\n";

// 2. Get users list
echo "2. Testing GET /api/v1/users...\n";
$usersResponse = Http::withToken($token)
    ->get('http://localhost:8000/api/v1/users?page=1&per_page=50');

echo "Users Status: " . $usersResponse->status() . "\n";

if ($usersResponse->failed()) {
    echo "ERROR: Request failed\n";
    echo "Headers: " . json_encode($usersResponse->headers(), JSON_PRETTY_PRINT) . "\n";
    echo "Body: " . $usersResponse->body() . "\n";
    exit(1);
}

$usersData = $usersResponse->json();

echo "\nAPI Response:\n";
echo json_encode($usersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\nSummary:\n";
echo "- Total users: " . count($usersData['data']) . "\n";
echo "- Pagination: page {$usersData['pagination']['current_page']}/{$usersData['pagination']['last_page']}\n";

echo "\nDone.\n";
