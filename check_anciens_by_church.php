<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=eecsigncard', 'root', '');

// Anciens par église
echo "=== ANCIENS PAR ÉGLISE ===" . PHP_EOL;
$result = $pdo->query('SELECT churchId, COUNT(*) as count FROM users WHERE role="ancien" AND actif=1 GROUP BY churchId');
foreach ($result as $row) {
    echo "Église " . $row['churchId'] . ": " . $row['count'] . " anciens" . PHP_EOL;
}

echo "\n=== DÉTAIL ÉGLISE 1 ===" . PHP_EOL;
$result = $pdo->query('SELECT id, nom, actif FROM users WHERE role="ancien" AND churchId=1');
foreach ($result as $row) {
    echo $row['nom'] . " (ID: " . $row['id'] . ", Actif: " . $row['actif'] . ")" . PHP_EOL;
}

echo "\n=== DÉSIGNATIONS MAI 2026 ÉGLISE 1 ===" . PHP_EOL;
$result = $pdo->query('SELECT id, ancienId, mois, annee FROM ancien_designations WHERE egliseId=1 AND mois=5 AND annee=2026');
$count = 0;
foreach ($result as $row) {
    echo "Désignation ID " . $row['id'] . ": Ancien ID " . $row['ancienId'] . PHP_EOL;
    $count++;
}
echo "Total: " . $count . " désignations pour mai 2026" . PHP_EOL;
