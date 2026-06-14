#!/usr/bin/env php
<?php
/**
 * MODULE TESTING SCRIPT
 * Tests all 9 modules independently to identify anomalies
 * Usage: php module_test.php
 */

$output = [];
$baseUrl = 'http://localhost:8000/api/v1';

// Color codes
$GREEN = "\033[32m";
$RED = "\033[31m";
$YELLOW = "\033[33m";
$BLUE = "\033[34m";
$RESET = "\033[0m";

function makeCurl($method, $endpoint, $data = null, $token = null) {
    global $baseUrl;
    
    $url = $baseUrl . $endpoint;
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => $response ? json_decode($response, true) : null,
        'error' => $error,
    ];
}

echo "\n{$BLUE}========================================{$RESET}\n";
echo "{$BLUE}MODULE INTEGRATION TEST SUITE{$RESET}\n";
echo "{$BLUE}========================================{$RESET}\n\n";

// TEST 1: AUTH MODULE - LOGIN
echo "{$YELLOW}[TEST 1] AUTH MODULE - LOGIN{$RESET}\n";
$loginResponse = makeCurl('POST', '/auth/login', [
    'email' => 'test@example.com',
    'password' => 'password123'
]);

$token = null;
if ($loginResponse['code'] == 200 && isset($loginResponse['body']['data']['access_token'])) {
    $token = $loginResponse['body']['data']['access_token'];
    echo "{$GREEN}✓ LOGIN SUCCESSFUL{$RESET}\n";
    echo "  - Status: {$loginResponse['code']}\n";
    echo "  - Token: " . substr($token, 0, 20) . "...\n";
    
    // Check for est_de_service flag
    if (isset($loginResponse['body']['data']['est_de_service'])) {
        echo "  - {$GREEN}✓ est_de_service flag present{$RESET}\n";
    } else {
        echo "  - {$RED}✗ est_de_service flag MISSING{$RESET}\n";
    }
} else {
    echo "{$RED}✗ LOGIN FAILED{$RESET}\n";
    echo "  - Status: {$loginResponse['code']}\n";
    echo "  - Error: " . ($loginResponse['error'] ?? 'Unknown') . "\n";
    if ($loginResponse['body']) {
        echo "  - Message: " . ($loginResponse['body']['message'] ?? json_encode($loginResponse['body'])) . "\n";
    }
}

// TEST 2: AUTH MODULE - /ME ENDPOINT
echo "\n{$YELLOW}[TEST 2] AUTH MODULE - /ME ENDPOINT{$RESET}\n";
if ($token) {
    $meResponse = makeCurl('GET', '/auth/me', null, $token);
    if ($meResponse['code'] == 200) {
        echo "{$GREEN}✓ /ME ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$meResponse['code']}\n";
        
        if (isset($meResponse['body']['data']['est_de_service'])) {
            echo "  - {$GREEN}✓ est_de_service flag present{$RESET}\n";
            echo "  - Value: " . ($meResponse['body']['data']['est_de_service'] ? 'true' : 'false') . "\n";
        } else {
            echo "  - {$RED}✗ est_de_service flag MISSING{$RESET}\n";
        }
    } else {
        echo "{$RED}✗ /ME ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$meResponse['code']}\n";
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

// TEST 3: USER MODULE
echo "\n{$YELLOW}[TEST 3] USER MODULE - /USERS{$RESET}\n";
if ($token) {
    $usersResponse = makeCurl('GET', '/users', null, $token);
    if ($usersResponse['code'] == 200) {
        echo "{$GREEN}✓ /USERS ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$usersResponse['code']}\n";
        echo "  - Count: " . count($usersResponse['body']['data'] ?? []) . "\n";
        
        if (count($usersResponse['body']['data'] ?? []) > 0) {
            $user = $usersResponse['body']['data'][0];
            if (isset($user['est_de_service'])) {
                echo "  - {$GREEN}✓ est_de_service in user response{$RESET}\n";
            } else {
                echo "  - {$RED}✗ est_de_service MISSING in user response{$RESET}\n";
                echo "    Fields: " . implode(', ', array_keys($user)) . "\n";
            }
        }
    } else {
        echo "{$RED}✗ /USERS ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$usersResponse['code']}\n";
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

// TEST 4: ANCIEN DESIGNATION MODULE
echo "\n{$YELLOW}[TEST 4] ANCIEN DESIGNATION MODULE{$RESET}\n";
if ($token) {
    $desResponse = makeCurl('GET', '/anciens-designations', null, $token);
    if ($desResponse['code'] == 200) {
        echo "{$GREEN}✓ /ANCIENS-DESIGNATIONS ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$desResponse['code']}\n";
        echo "  - Count: " . count($desResponse['body']['data'] ?? []) . "\n";
        
        if (count($desResponse['body']['data'] ?? []) > 0) {
            $des = $desResponse['body']['data'][0];
            $requiredFields = ['id', 'egliseId', 'ancienId', 'mois_designation', 'statut_designation'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (!isset($des[$field])) {
                    $missingFields[] = $field;
                }
            }
            
            if (empty($missingFields)) {
                echo "  - {$GREEN}✓ All required fields present{$RESET}\n";
            } else {
                echo "  - {$RED}✗ Missing fields: " . implode(', ', $missingFields) . "{$RESET}\n";
            }
        }
    } else {
        echo "{$RED}✗ /ANCIENS-DESIGNATIONS ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$desResponse['code']}\n";
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

// TEST 5: SIGNATURE MODULE
echo "\n{$YELLOW}[TEST 5] SIGNATURE MODULE - /SIGNATURES/MA-CARTE{$RESET}\n";
if ($token) {
    $sigResponse = makeCurl('GET', '/signatures/ma-carte', null, $token);
    if ($sigResponse['code'] == 200) {
        echo "{$GREEN}✓ /SIGNATURES/MA-CARTE ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$sigResponse['code']}\n";
    } else {
        echo "{$RED}✗ /SIGNATURES/MA-CARTE ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$sigResponse['code']}\n";
        if ($sigResponse['body']) {
            echo "  - Message: " . ($sigResponse['body']['message'] ?? 'Unknown error') . "\n";
        }
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

// TEST 6: PUBLICATION MODULE
echo "\n{$YELLOW}[TEST 6] PUBLICATION MODULE - /PUBLICATIONS{$RESET}\n";
if ($token) {
    $pubResponse = makeCurl('GET', '/publications', null, $token);
    if ($pubResponse['code'] == 200) {
        echo "{$GREEN}✓ /PUBLICATIONS ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$pubResponse['code']}\n";
        echo "  - Count: " . count($pubResponse['body']['data'] ?? []) . "\n";
    } else {
        echo "{$RED}✗ /PUBLICATIONS ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$pubResponse['code']}\n";
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

// TEST 7: CHURCH MODULE
echo "\n{$YELLOW}[TEST 7] CHURCH MODULE - /CHURCHES{$RESET}\n";
if ($token) {
    $churchResponse = makeCurl('GET', '/churches', null, $token);
    if ($churchResponse['code'] == 200) {
        echo "{$GREEN}✓ /CHURCHES ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$churchResponse['code']}\n";
        echo "  - Count: " . count($churchResponse['body']['data'] ?? []) . "\n";
    } else {
        echo "{$RED}✗ /CHURCHES ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$churchResponse['code']}\n";
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

// TEST 8: RAPPORT MODULE
echo "\n{$YELLOW}[TEST 8] RAPPORT MODULE - /RAPPORTS/SECRETAIRE{$RESET}\n";
if ($token) {
    $rapResponse = makeCurl('GET', '/rapports/secretaire', null, $token);
    if ($rapResponse['code'] == 200) {
        echo "{$GREEN}✓ /RAPPORTS/SECRETAIRE ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$rapResponse['code']}\n";
    } else {
        echo "{$RED}✗ /RAPPORTS/SECRETAIRE ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$rapResponse['code']}\n";
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

// TEST 9: PAYMENT MODULE
echo "\n{$YELLOW}[TEST 9] PAYMENT MODULE - /PAYMENTS{$RESET}\n";
if ($token) {
    $payResponse = makeCurl('GET', '/payments', null, $token);
    if ($payResponse['code'] == 200) {
        echo "{$GREEN}✓ /PAYMENTS ENDPOINT SUCCESSFUL{$RESET}\n";
        echo "  - Status: {$payResponse['code']}\n";
    } else {
        echo "{$RED}✗ /PAYMENTS ENDPOINT FAILED{$RESET}\n";
        echo "  - Status: {$payResponse['code']}\n";
    }
} else {
    echo "{$YELLOW}⊘ SKIPPED (no token){$RESET}\n";
}

echo "\n{$BLUE}========================================{$RESET}\n";
echo "{$BLUE}TEST SUITE COMPLETED{$RESET}\n";
echo "{$BLUE}========================================{$RESET}\n\n";
