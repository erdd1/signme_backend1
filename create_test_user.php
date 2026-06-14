<?php

require __DIR__ . '/bootstrap/app.php';

use App\Models\User;
use App\Models\Church;
use Illuminate\Support\Facades\Hash;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Create church
$church = Church::firstOrCreate(
    ['email' => 'church@test.com'],
    [
        'nom' => 'Test Church',
        'telephone' => '1234567890',
        'adresse' => '123 Test St',
        'ville' => 'Test City',
        'codePostal' => '12345',
        'pays' => 'Test Country',
        'actif' => true,
    ]
);

echo "Church created/found: ID = {$church->id}\n";

// Create test user
$user = User::firstOrCreate(
    ['email' => 'test@example.com'],
    [
        'nom' => 'Test User',
        'role' => 'pasteur',
        'churchId' => $church->id,
        'password' => Hash::make('password123'),
        'actif' => true,
        'est_de_service' => false,
    ]
);

echo "User created/found: ID = {$user->id}, Email = {$user->email}\n";
echo "\nTest credentials:\n";
echo "Email: test@example.com\n";
echo "Password: password123\n";
