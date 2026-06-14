<?php
/**
 * Script pour tester la méthode rapportSecretaire directement
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Modules\Rapport\Application\Services\RapportService;

echo "=== TEST RAPPORT SERVICE ===\n\n";

// Utiliser l'utilisateur avec ID 2 (yvan@gmail.com) qui a Église #1
$user = User::find(2);
if (!$user) {
    echo "Utilisateur non trouvé!\n";
    exit(1);
}

echo "Utilisateur testé: {$user->nom} ({$user->email})\n";
echo "Église: {$user->churchId}\n";
echo "Role: {$user->role}\n\n";

$service = new RapportService();
$currentMonth = date('Y-m');

echo "Mois demandé: $currentMonth\n\n";

try {
    $rapport = $service->rapportSecretaire($user, $currentMonth);
    
    echo "=== RÉSULTAT API ===\n";
    echo "Total Membres: {$rapport['total_membres']}\n";
    echo "Total Signés: {$rapport['total_signes']}\n";
    echo "Total Non Signés: {$rapport['total_non_signes']}\n";
    echo "Taux Signature: {$rapport['taux_signature']}%\n";
    echo "Montant Collecté: {$rapport['montant_collecte']}\n";
    echo "Mois: {$rapport['month']}\n";
    echo "Église ID: {$rapport['eglise_id']}\n\n";
    
    echo "=== LISTES ===\n";
    echo "Nombre de signés: " . count($rapport['signes']) . "\n";
    echo "Nombre de non signés: " . count($rapport['non_signes']) . "\n\n";
    
    if (count($rapport['signes']) > 0) {
        echo "Premier signé:\n";
        print_r($rapport['signes'][0]);
    }
    
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    echo "Stack:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== FIN TEST ===\n";
