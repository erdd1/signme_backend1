<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=eecsigncard', 'root', '');
$result = $pdo->query('SELECT id, nom, email, role, churchId FROM users WHERE role IN ("pasteur", "ancien") ORDER BY role');

echo "=== Comptes TEST ===" . PHP_EOL;
foreach ($result as $row) {
    $church = $row['churchId'] ?? 'NULL';
    echo $row['role'] . ': ' . $row['nom'] . ' (ID: ' . $row['id'] . ', Church: ' . $church . ')' . PHP_EOL;
}
