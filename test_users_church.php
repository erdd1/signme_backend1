<?php
/**
 * Script pour vérifier les données d'utilisateurs et leurs églises
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

echo "=== UTILISATEURS ET LEURS ÉGLISES ===\n\n";

$users = User::select('id', 'nom', 'email', 'role', 'churchId', 'actif')->get();

foreach ($users as $user) {
    $churchInfo = $user->churchId ? "Église #{$user->churchId}" : "❌ AUCUNE ÉGLISE";
    $actif = $user->actif ? "✓ Actif" : "✗ Inactif";
    echo "[ID: {$user->id}] {$user->nom} ({$user->email})\n";
    echo "  Role: {$user->role} | $actif | $churchInfo\n\n";
}

// Compter les utilisateurs sans église
$withoutChurch = User::whereNull('churchId')->orWhere('churchId', 0)->count();
$withChurch = User::whereNotNull('churchId')->where('churchId', '!=', 0)->count();

echo "\n=== RÉSUMÉ ===\n";
echo "Utilisateurs WITH churchId: $withChurch\n";
echo "Utilisateurs WITHOUT churchId: $withoutChurch\n";
echo "Total: " . ($withChurch + $withoutChurch) . "\n";
