<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Signature;

echo "Suppression de la vieille signature échouée...\n";

$deleted = Signature::where('userId', 2)
                    ->where('month', '2026-03')
                    ->where('status', 'failed')
                    ->delete();

echo "Signatures supprimées: " . $deleted . "\n";

$remaining = Signature::where('userId', 2)
                      ->where('month', '2026-03')
                      ->count();

echo "Signatures restantes pour 2-2026-03: " . $remaining . "\n";

if ($remaining === 0) {
    echo "\n✅ Base de données nettoyée! Vous pouvez maintenant effectuer un nouveau paiement.\n";
}
