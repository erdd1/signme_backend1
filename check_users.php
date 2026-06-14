<?php
require 'vendor/autoload.php';
\Illuminate\Support\Facades\DB::connection();

$pasteurs = \App\Models\User::where('role', 'pasteur')->limit(5)->get(['id', 'nom', 'email', 'churchId']);

foreach ($pasteurs as $user) {
    echo $user->nom . ' (ID: ' . $user->id . ', ChurchId: ' . ($user->churchId ?? 'NULL') . ')' . PHP_EOL;
}

echo "\n=== ANCIENS ===\n";
$anciens = \App\Models\User::where('role', 'ancien')->limit(5)->get(['id', 'nom', 'email', 'churchId']);

foreach ($anciens as $user) {
    echo $user->nom . ' (ID: ' . $user->id . ', ChurchId: ' . ($user->churchId ?? 'NULL') . ')' . PHP_EOL;
}
