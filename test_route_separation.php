<?php

/**
 * Test Route Separation: Admin vs Client
 * ======================================
 * Tests that:
 * 1. Admin users can ONLY access /api/admin/*
 * 2. Client users (non-admin) can ONLY access /api/v1/*
 * 3. Each role is rejected from the other's routes
 */

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

// Test data
$test_results = [];
$base_url = 'http://localhost:8000';

// ============================================================================
// Setup: Create test users if needed
// ============================================================================
use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "Testing Route Separation: Admin vs Client\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// Find or create admin user
$admin = User::where('role', 'administrateur')->first();
if (!$admin) {
    echo "❌ ERROR: No admin user found in database\n";
    exit(1);
}
echo "✓ Admin user found: {$admin->email}\n";

// Find or create pasteur user
$pasteur = User::where('role', 'pasteur')->first();
if (!$pasteur) {
    echo "❌ ERROR: No pasteur user found in database\n";
    exit(1);
}
echo "✓ Pasteur user found: {$pasteur->email}\n";

// Generate tokens for both users
$admin_token = $admin->createToken('test-admin')->plainTextToken;
$pasteur_token = $pasteur->createToken('test-pasteur')->plainTextToken;

echo "\n✓ Tokens generated\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// ============================================================================
// Test Cases
// ============================================================================

// Test 1: Admin accessing /api/v1 endpoint (should be rejected with 403)
echo "TEST 1: Admin accessing /api/v1/users (should be 403)\n";
echo "─────────────────────────────────────────────────────────────────────────\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$base_url/api/v1/users",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $admin_token"],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response_data = json_decode($response, true);
$status = ($http_code === 403) ? '✓ PASS' : '❌ FAIL';
$test_results['admin_v1_rejection'] = ($http_code === 403);
echo "Status: $http_code - $status\n";
echo "Message: " . ($response_data['message'] ?? 'N/A') . "\n\n";

// Test 2: Pasteur accessing /api/v1 endpoint (should succeed with 200)
echo "TEST 2: Pasteur accessing /api/v1/users (should be 200)\n";
echo "─────────────────────────────────────────────────────────────────────────\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$base_url/api/v1/users",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $pasteur_token"],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response_data = json_decode($response, true);
$status = ($http_code === 200) ? '✓ PASS' : '❌ FAIL';
$test_results['pasteur_v1_access'] = ($http_code === 200);
echo "Status: $http_code - $status\n";
echo "Records: " . (isset($response_data['data']) ? count($response_data['data']) : 0) . "\n\n";

// Test 3: Admin accessing /api/admin endpoint (should succeed with 200)
echo "TEST 3: Admin accessing /api/admin/users (should be 200)\n";
echo "─────────────────────────────────────────────────────────────────────────\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$base_url/api/admin/users",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $admin_token"],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response_data = json_decode($response, true);
$status = ($http_code === 200) ? '✓ PASS' : '❌ FAIL';
$test_results['admin_admin_access'] = ($http_code === 200);
echo "Status: $http_code - $status\n";
echo "Records: " . (isset($response_data['data']) ? count($response_data['data']) : 0) . "\n\n";

// Test 4: Pasteur accessing /api/admin endpoint (should be rejected with 403)
echo "TEST 4: Pasteur accessing /api/admin/users (should be 403)\n";
echo "─────────────────────────────────────────────────────────────────────────\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$base_url/api/admin/users",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $pasteur_token"],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response_data = json_decode($response, true);
$status = ($http_code === 403) ? '✓ PASS' : '❌ FAIL';
$test_results['pasteur_admin_rejection'] = ($http_code === 403);
echo "Status: $http_code - $status\n";
echo "Message: " . ($response_data['message'] ?? 'N/A') . "\n\n";

// ============================================================================
// Summary
// ============================================================================
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";

$passed = array_sum($test_results);
$total = count($test_results);

foreach ($test_results as $test_name => $result) {
    $status = $result ? '✓' : '❌';
    echo "$status $test_name\n";
}

echo "\nTotal: $passed/$total tests passed\n";
if ($passed === $total) {
    echo "✓ All route separation tests PASSED!\n";
} else {
    echo "❌ Some tests FAILED!\n";
}
echo "═══════════════════════════════════════════════════════════════════════════\n";
