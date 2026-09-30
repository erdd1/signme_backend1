<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_requires_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_fails_with_invalid_credentials(): void
{
    User::factory()->create([
        'email' => 'user@test.com',
        'password' => bcrypt('password123'),
        'role' => 'paroissien',
        'actif' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'user@test.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401);
}

public function test_inactive_user_cannot_login(): void
{
    User::factory()->create([
        'email' => 'inactive@test.com',
        'password' => bcrypt('password123'),
        'role' => 'paroissien',
        'actif' => false,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@test.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(403);
}

public function test_administrator_cannot_login_on_mobile(): void
{
    User::factory()->create([
        'email' => 'admin@test.com',
        'password' => bcrypt('password123'),
        'role' => 'administrateur',
        'is_super_admin' => true,
        'actif' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(403);
}

public function test_user_can_login_with_valid_credentials(): void
{
    $user = User::factory()->create([
        'nom' => 'Jean Dupont',
        'email' => 'jean@test.com',
        'password' => bcrypt('password123'),
        'role' => 'paroissien',
        'actif' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'jean@test.com',
        'password' => 'password123',
    ]);

    $response
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $response->assertJsonStructure([
        'success',
        'message',
        'data' => [
            'id',
            'nom',
            'email',
            'role',
            'actif',
            'est_de_service',
            'access_token',
            'refresh_token',
            'token_type',
            'expires_in',
        ],
    ]);
}



}