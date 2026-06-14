<?php
/**
 * Script de diagnostic pour identifier les colonnes et tables ajoutées au-delà des migrations restaurées
 */

// Configuration de la base de données
$host = '127.0.0.1';
$db = 'eecsigncard';
$user = 'root';
$password = '';

try {
    // Connexion à MySQL
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "============================================\n";
    echo "DIAGNOSTIC BASE DE DONNÉES\n";
    echo "============================================\n\n";
    
    // 1. Lister toutes les tables
    echo "1. TABLES EXISTANTES:\n";
    echo "-------------------\n";
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "  - $table\n";
    }
    echo "\n";
    
    // 2. Pour chaque table, afficher sa structure
    echo "2. STRUCTURE DÉTAILLÉE DES TABLES:\n";
    echo "----------------------------------\n\n";
    
    foreach ($tables as $table) {
        echo "TABLE: $table\n";
        echo str_repeat("-", 80) . "\n";
        
        $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
        
        echo sprintf("%-20s | %-15s | %-10s | %-10s | %-30s\n", 
            "Field", "Type", "Null", "Key", "Default/Extra");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($columns as $column) {
            printf("%-20s | %-15s | %-10s | %-10s | %-30s\n",
                $column['Field'],
                $column['Type'],
                $column['Null'] ?? 'NO',
                $column['Key'] ?? '',
                ($column['Extra'] ?? '') . (isset($column['Default']) ? '(Default: ' . $column['Default'] . ')' : '')
            );
        }
        echo "\n\n";
    }
    
    // 3. Vérifier les migrations exécutées
    echo "3. MIGRATIONS EXÉCUTÉES:\n";
    echo "------------------------\n";
    
    $migrations = $pdo->query("SELECT * FROM migrations ORDER BY batch, migration")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($migrations as $migration) {
        echo sprintf("  [%d] %s\n", $migration['batch'], $migration['migration']);
    }
    echo "\n";
    
    // 4. Analyser les colonnes non-standard
    echo "4. COLONNES PAR TABLE:\n";
    echo "---------------------\n\n";
    
    $allColumnsInfo = [];
    foreach ($tables as $table) {
        $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
        $allColumnsInfo[$table] = $columns;
        
        echo "TABLE: $table\n";
        foreach ($columns as $col) {
            echo sprintf("  - %s (%s)\n", $col['Field'], $col['Type']);
        }
        echo "\n";
    }
    
    // 5. Résumé pour le diagnostic
    echo "5. RAPPORT DE DIAGNOSTIC:\n";
    echo "-------------------------\n";
    echo "Nombre total de tables: " . count($tables) . "\n";
    echo "Nombre total de migrations exécutées: " . count($migrations) . "\n\n";
    
    echo "TABLES ATTENDUES (basées sur les migrations):\n";
    $expectedTables = [
        'users',
        'cache',
        'jobs',
        'churches',
        'signatures',
        'payment_transactions',
        'ancien_designations',
        'publications',
        'notifications',
        'personal_access_tokens',
        'refresh_tokens',
        'login_attempts',
        'security_logs',
        'email_verifications',
        'mfa_credentials',
        'device_sessions',
        'suspicious_activities',
        'otp_codes',
        'signature_logs',
        'migrations',
        'failed_jobs',
        'password_reset_tokens',
        'sessions',
        'job_batches'
    ];
    
    foreach ($expectedTables as $expected) {
        $exists = in_array($expected, $tables) ? '✓' : '✗';
        echo "  $exists $expected\n";
    }
    
    echo "\n\nTABLES NON ATTENDUES (potentiellement ajoutées par le nouveau module):\n";
    $unexpectedTables = array_diff($tables, $expectedTables);
    if (!empty($unexpectedTables)) {
        foreach ($unexpectedTables as $table) {
            echo "  ! $table\n";
        }
    } else {
        echo "  Aucune table inattendue détectée\n";
    }
    
    echo "\n============================================\n";
    
} catch (PDOException $e) {
    echo "ERREUR DE CONNEXION: " . $e->getMessage() . "\n";
    exit(1);
}
