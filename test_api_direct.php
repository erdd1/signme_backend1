<?php
require 'vendor/autoload.php';

// Simpler test - just check if API returns data
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://192.168.8.101:8000/api/v1/anciens-designations?mois=5&annee=2026');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOlwvXC8xOTIuMTY4LjguMTAxOjgwMDAiLCJhdWQiOiJodHRwOlwvXC8xOTIuMTY4LjguMTAxOjgwMDAiLCJpYXQiOjE3MTQ5MzI5MjIsImV4cCI6MTcxNDkzNjUyMn0.redacted',
    'Content-Type: application/json'
]);

echo "Testing API endpoint...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo "CURL Error: " . curl_error($ch) . "\n";
} else {
    echo "HTTP Code: $httpCode\n";
    echo "Response:\n";
    echo json_encode(json_decode($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

curl_close($ch);
