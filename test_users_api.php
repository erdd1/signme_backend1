<?php
/**
 * Test script to check users API
 * Run: php test_users_api.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';

use Illuminate\Support\Facades\DB;
use App\Models\User;

// Start Laravel
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== DEBUGGING USERS API ===\n\n";

// 1. Check current user (test user)
echo "1. Checking pasteur/secretaire users:\n";
$pasteurs = User::where('role', 'pasteur')->with('church')->get();
echo "Pasteurs: " . $pasteurs->count() . "\n";
$pasteurs->each(function($u) {
    $churchName = isset($u->church) ? $u->church->nom : 'N/A';
    echo "  - ID: {$u->id}, Nom: {$u->nom}, Church: {$churchName} (ID: {$u->churchId})\n";
});

echo "\n2. Checking users per church:\n";
DB::table('users')
    ->select('churchId', DB::raw('count(*) as count'))
    ->groupBy('churchId')
    ->get()
    ->each(function($row) {
        $church = DB::table('churches')->find($row->churchId);
        $churchName = isset($church) ? $church->nom : 'N/A';
        echo "  Church ID {$row->churchId} ({$churchName}): {$row->count} users\n";
    });

echo "\n3. Testing pasteur user:\n";
$testPasteur = User::where('role', 'pasteur')->first();
if ($testPasteur) {
    echo "Found pasteur: {$testPasteur->nom} (ID: {$testPasteur->id}, Church: {$testPasteur->churchId})\n";
    echo "Users in his church:\n";
    $usersInChurch = User::where('churchId', $testPasteur->churchId)->with('church')->get();
    echo "  Total: " . $usersInChurch->count() . "\n";
    $usersInChurch->each(function($u) {
        echo "    - {$u->nom} ({$u->role})\n";
    });
} else {
    echo "No pasteur found!\n";
}

echo "\nDone.\n";
