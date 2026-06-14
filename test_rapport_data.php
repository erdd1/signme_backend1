<?php
/**
 * Script de test pour vérifier les données du rapport secrétaire
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Signature;
use Illuminate\Support\Facades\DB;

$currentMonth = date('Y-m');
echo "=== TEST RAPPORT SECRETAIRE ===\n";
echo "Mois courant: $currentMonth\n\n";

// 1. Compter les utilisateurs actifs
$usersCount = User::where('actif', true)
                   ->whereIn('role', ['paroissien', 'ancien', 'secretaire', 'tresorier', 'pasteur'])
                   ->count();
echo "1. Utilisateurs actifs: $usersCount\n";

// 2. Compter les signatures complétées ce mois
$signaturesCount = Signature::where('month', $currentMonth)
                             ->where('status', 'completed')
                             ->count();
echo "2. Signatures complétées ($currentMonth): $signaturesCount\n";

// 3. Montant collecté ce mois
$totalAmount = Signature::where('month', $currentMonth)
                         ->where('status', 'completed')
                         ->sum('amount');
echo "3. Montant collecté ($currentMonth): $totalAmount\n";

// 4. Vérifier les signatures par église
echo "\n4. Signatures par église:\n";
$sigsByChurch = Signature::where('month', $currentMonth)
                          ->where('status', 'completed')
                          ->groupBy('churchId')
                          ->selectRaw('churchId, count(*) as total, sum(amount) as montant')
                          ->get();

foreach ($sigsByChurch as $sig) {
    echo "   - Église ID: {$sig->churchId}, Signatures: {$sig->total}, Montant: {$sig->montant}\n";
}

// 5. Vérifier les utilisateurs par église
echo "\n5. Utilisateurs actifs par église:\n";
$usersByChurch = User::where('actif', true)
                      ->whereIn('role', ['paroissien', 'ancien', 'secretaire', 'tresorier', 'pasteur'])
                      ->groupBy('churchId')
                      ->selectRaw('churchId, count(*) as total')
                      ->get();

foreach ($usersByChurch as $user) {
    echo "   - Église ID: {$user->churchId}, Utilisateurs: {$user->total}\n";
}

// 6. Vérifier les données brutes pour le mois courant
echo "\n6. Données brutes du mois ($currentMonth):\n";
$rawData = DB::table('signatures')
              ->where('month', $currentMonth)
              ->where('status', 'completed')
              ->selectRaw('
                  COUNT(*) as total_signatures,
                  SUM(amount) as total_montant,
                  COUNT(DISTINCT churchId) as distinct_churches,
                  COUNT(DISTINCT userId) as distinct_users
              ')
              ->first();

if ($rawData) {
    echo "   - Total signatures: {$rawData->total_signatures}\n";
    echo "   - Montant total: {$rawData->total_montant}\n";
    echo "   - Églises différentes: {$rawData->distinct_churches}\n";
    echo "   - Utilisateurs différents: {$rawData->distinct_users}\n";
}

// 7. Montrer un exemple de signature
echo "\n7. Exemple de signature:\n";
$sample = Signature::where('month', $currentMonth)
                    ->where('status', 'completed')
                    ->first();

if ($sample) {
    echo "   - ID: {$sample->id}\n";
    echo "   - User ID: {$sample->userId}\n";
    echo "   - Church ID: {$sample->churchId}\n";
    echo "   - Month: {$sample->month}\n";
    echo "   - Amount: {$sample->amount}\n";
    echo "   - Status: {$sample->status}\n";
} else {
    echo "   - Aucune signature trouvée pour ce mois!\n";
}

echo "\n=== FIN TEST ===\n";
