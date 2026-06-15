<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_requires_token(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh', []);

        $response->assertStatus(422);
    }

    public function test_malformed_refresh_token_is_rejected(): void
{
    $response = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => 'invalid-token',
    ]);

    $response->assertStatus(422);
}

public function test_unknown_refresh_token_is_rejected(): void
{
    $response = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => str_repeat('a', 80),
    ]);

    $response->assertStatus(401);
}

public function test_refresh_token_rotation(): void
{
    // 1. Créer utilisateur
    $user = \App\Models\User::factory()->create([
        'nom' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'role' => 'pasteur',
        'actif' => true,
    ]);

    // 2. Login
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $loginResponse->assertStatus(200);

    $oldRefreshToken = $loginResponse->json('data.refresh_token');

    $this->assertNotNull($oldRefreshToken);

    // 3. Rafraîchir le token
    $refreshResponse = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $oldRefreshToken,
    ]);

    $refreshResponse->assertStatus(200);

    $newRefreshToken = $refreshResponse->json('data.refresh_token');

    $this->assertNotNull($newRefreshToken);

    $this->assertNotEquals(
        $oldRefreshToken,
        $newRefreshToken
    );

    // 4. Réutiliser l'ancien token
    $reuseResponse = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $oldRefreshToken,
    ]);

    $reuseResponse->assertStatus(401);
}

}