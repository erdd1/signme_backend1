<?php
/**
 * Diagnostic complet de la base de données avec Laravel Tinker
 * Lance: php artisan tinker < diagnose_with_tinker.php
 */

// Afficher toutes les tables existantes
$tables = DB::select("SHOW TABLES");
echo "============================================\n";
echo "TABLES EXISTANTES\n";
echo "============================================\n";
foreach ($tables as $table) {
    $tableName = reset((array)$table);
    echo "- $tableName\n";
}

// Afficher toutes les migrations exécutées
echo "\n============================================\n";
echo "MIGRATIONS EXÉCUTÉES\n";
echo "============================================\n";
$migrations = DB::table('migrations')->orderBy('batch')->get();
foreach ($migrations as $m) {
    echo "[$m->batch] $m->migration\n";
}

// Identifier les migrations qui ne devraient pas être là
echo "\n============================================\n";
echo "MIGRATIONS À NETTOYER\n";
echo "============================================\n";
$unwantedMigrations = $migrations->filter(function($m) {
    return strpos($m->migration, '2026_05') === 0;
});

if ($unwantedMigrations->count() > 0) {
    echo "Migrations du 2026-05 (à SUPPRIMER):\n";
    foreach ($unwantedMigrations as $m) {
        echo "  [DELETE] $m->migration\n";
    }
} else {
    echo "Aucune migration du 2026-05 détectée.\n";
}

// Afficher la structure de quelques tables clés
echo "\n============================================\n";
echo "COLONNES PAR TABLE (Tables clés)\n";
echo "============================================\n";

$tablesToCheck = ['users', 'signatures', 'ancien_designations', 'payment_transactions'];

foreach ($tablesToCheck as $table) {
    if (in_array($table, array_map(fn($t) => reset((array)$t), $tables))) {
        echo "\nTABLE: $table\n";
        $columns = DB::select("DESCRIBE $table");
        foreach ($columns as $col) {
            echo sprintf("  - %s (%s)\n", $col->Field, $col->Type);
        }
    }
}

exit(0);
