<?php

namespace Tests\Feature\User;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasteur_can_create_paroissien(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
            'actif' => true,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'nom' => 'Jean Dupont',
                'email' => 'jean@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'jean@test.com',
            'role' => User::ROLE_PAROISSIEN,
        ]);
    }
}