<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RBACPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $role = 'paroissien'): array
    {
        return [
            'nom' => 'Utilisateur Test',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'role' => $role,
        ];
    }

    public function test_guest_cannot_create_user(): void
    {
        $response = $this->postJson('/api/v1/users', $this->payload());

        $response->assertStatus(401);
    }

    public function test_paroissien_cannot_create_user(): void
    {
        $user = User::factory()->create([
            'role' => 'paroissien',
            'actif' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users', $this->payload());

        $response->assertStatus(403);
    }

    public function test_ancien_cannot_create_user(): void
    {
        $user = User::factory()->create([
            'role' => 'ancien',
            'actif' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users', $this->payload());

        $response->assertStatus(403);
    }

    public function test_tresorier_cannot_create_user(): void
    {
        $user = User::factory()->create([
            'role' => 'tresorier',
            'actif' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/users', $this->payload());

        $response->assertStatus(403);
    }

    public function test_pasteur_can_create_paroissien(): void
    {
        $user = User::factory()->create([
            'role' => 'pasteur',
            'actif' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/users',
            $this->payload('paroissien')
        );

        $response->assertStatus(201);
    }

    public function test_secretaire_can_create_paroissien(): void
    {
        $user = User::factory()->create([
            'role' => 'secretaire',
            'actif' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/users',
            $this->payload('paroissien')
        );

        $response->assertStatus(201);
    }

    public function test_secretaire_cannot_create_pasteur(): void
{
    $secretaire = User::factory()->create([
        'role' => User::ROLE_SECRETAIRE,
        'actif' => true,
    ]);

    $token = $secretaire->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson('/api/v1/users', [
        'nom' => 'Pasteur Test',
        'email' => 'pasteur@test.com',
        'role' => User::ROLE_PASTEUR,
    ]);

    $response->assertStatus(403);
}


public function test_pasteur_cannot_create_administrateur(): void
{
    $pasteur = User::factory()->create([
        'role' => User::ROLE_PASTEUR,
        'actif' => true,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson('/api/v1/users', [
        'nom' => 'Admin Test',
        'email' => 'admin@test.com',
        'role' => User::ROLE_ADMIN,
    ]);

    $response->assertStatus(403);
}



}