<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'role' => 'pasteur',
            'actif' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $token
        )->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);

        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_logout_revokes_all_access_tokens(): void
{
    $user = User::factory()->create([
        'role' => 'pasteur',
        'actif' => true,
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $this->assertDatabaseCount('personal_access_tokens', 1);

    $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson('/api/v1/auth/logout');

    $this->assertDatabaseCount(
        'personal_access_tokens',
        0
    );
}

public function test_logout_revokes_refresh_tokens(): void
{
    $user = User::factory()->create([
        'nom' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'role' => 'pasteur',
        'actif' => true,
    ]);

    // Login
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $accessToken = $loginResponse->json('data.access_token');
    $refreshToken = $loginResponse->json('data.refresh_token');

    // Logout
    $this->withHeader(
        'Authorization',
        'Bearer '.$accessToken
    )->postJson('/api/v1/auth/logout')
     ->assertStatus(200);

    // Tentative refresh après logout
    $refreshResponse = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $refreshToken,
    ]);

    $refreshResponse->assertStatus(401);
}



}