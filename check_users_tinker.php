<?php

use App\Models\User;

return function () {
    $pasteurs = User::where('role', 'pasteur')->limit(5)->get(['id', 'nom', 'email', 'churchId']);
    
    echo "=== PASTEURS ===\n";
    foreach ($pasteurs as $user) {
        echo $user->nom . ' (ID: ' . $user->id . ', ChurchId: ' . ($user->churchId ?? 'NULL') . ')' . PHP_EOL;
    }
    
    echo "\n=== ANCIENS ===\n";
    $anciens = User::where('role', 'ancien')->limit(5)->get(['id', 'nom', 'email', 'churchId']);
    
    foreach ($anciens as $user) {
        echo $user->nom . ' (ID: ' . $user->id . ', ChurchId: ' . ($user->churchId ?? 'NULL') . ')' . PHP_EOL;
    }
};
