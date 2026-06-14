<?php

// Configuration
$reference = '3cb87d32-f14d-4c98-8f13-c4074ebd0cfc';  // ← Remplace par ta vraie référence
$secret = 'CXe2OMTWfv3wO9DcmCDfffWgWaqicUdCi4vCBsn2w700R9uzYptO5OLiB7lEm7oT3NTmAuGjYK1PA2KNJAJ3BA';

// Payload (exactement comme Campay l'enverrait)
$payload = [
    'reference' => $reference,
    'status' => 'SUCCESSFUL',
];

// Body brut en JSON
$rawBody = json_encode($payload);

// Générer le HMAC-SHA256
$hmac = hash_hmac('sha256', $rawBody, $secret);

echo "═══════════════════════════════════════════════════════\n";
echo "WEBHOOK TEST — Copie-colle dans Postman\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "📍 URL:\n";
echo "   POST http://192.168.8.101:8000/api/v1/webhooks/campay\n\n";

echo "📋 Headers:\n";
echo "   Content-Type: application/json\n";
echo "   X-Campay-Signature: {$hmac}\n\n";

echo "📦 Body (Raw JSON):\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

echo "✅ Valeurs à copier:\n";
echo "   Header:    X-Campay-Signature\n";
echo "   Valeur:    {$hmac}\n";
echo "   Reference: {$reference}\n";
echo "═══════════════════════════════════════════════════════\n";
