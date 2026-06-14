<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Signature;

echo "Cherchant signature pour userId=2, month=2026-03...\n";

$signatures = Signature::where('userId', 2)
                       ->where('month', '2026-03')
                       ->get();

echo "Nombre de signatures trouvées: " . $signatures->count() . "\n";

foreach ($signatures as $sig) {
    echo "\n✓ Signature trouvée:\n";
    echo "  ID: " . $sig->id . "\n";
    echo "  Status: " . $sig->status . "\n";
    echo "  Campay Ref: " . $sig->campay_reference . "\n";
    echo "  Created: " . $sig->created_at . "\n";
}
