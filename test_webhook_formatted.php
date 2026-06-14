<?php

// Configuration
$reference = '3cb87d32-f14d-4c98-8f13-c4074ebd0cfc';
$secret = 'CXe2OMTWfv3wO9DcmCDfffWgWaqicUdCi4vCBsn2w700R9uzYptO5OLiB7lEm7oT3NTmAuGjYK1PA2KNJAJ3BA';

// JSON FORMATÉ exactement comme Postman l'envoie (avec espaces/retours à la ligne)
$rawBody = <<<JSON
{

    "reference": "3cb87d32-f14d-4c98-8f13-c4074ebd0cfc",

    "status": "SUCCESSFUL"

}
JSON;

// Générer le HMAC-SHA256
$hmac = hash_hmac('sha256', $rawBody, $secret);

echo "═══════════════════════════════════════════════════════\n";
echo "WEBHOOK TEST — HMAC RECALCULÉ (JSON FORMATÉ)\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "📍 URL:\n";
echo "   POST http://localhost:8000/api/v1/webhooks/campay\n\n";

echo "📋 Headers:\n";
echo "   Content-Type: application/json\n";
echo "   X-Campay-Signature: {$hmac}\n\n";

echo "📦 Body (Raw JSON — AVEC FORMATAGE):\n";
echo $rawBody . "\n\n";

echo "✅ HMAC à utiliser dans Postman:\n";
echo "   {$hmac}\n";
echo "═══════════════════════════════════════════════════════\n";
