<?php
// Récupérer les infos du pasteur de test
$pdo = new PDO('mysql:host=127.0.0.1;dbname=eecsigncard', 'root', '');
$user = $pdo->query('SELECT id, email, nom FROM users WHERE role="pasteur" AND churchId=1 LIMIT 1')->fetch();
echo "Test Account: " . $user['nom'] . " (" . $user['email'] . ")" . PHP_EOL;
echo "Email: " . $user['email'] . PHP_EOL;
echo PHP_EOL . "Password: password123" . PHP_EOL;
