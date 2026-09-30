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
                'sexe' => 'homme',
                'matricule' => 'H001',
                'estCommuniant' => true,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'jean@test.com',
            'role' => User::ROLE_PAROISSIEN,
            'matricule' => 'H001',
        ]);
    }

    public function test_matricule_is_required_when_church_is_set(): void
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
                'email' => 'jean.nomatricule@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'sexe' => 'homme',
                'estCommuniant' => true,
            ]);

        $response->assertStatus(400);
    }

    public function test_matricule_format_is_validated(): void
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
                'email' => 'jean.badmatricule@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'sexe' => 'homme',
                'matricule' => 'X1',
            ]);

        $response->assertStatus(422);
    }

    public function test_matricule_prefix_must_match_sexe(): void
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
                'email' => 'jean.mismatch@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'sexe' => 'femme',
                'matricule' => 'H002',
                'estCommuniant' => true,
            ]);

        $response->assertStatus(400);
    }

    public function test_matricule_must_be_unique_within_church(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
            'actif' => true,
        ]);

        User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'sexe' => 'homme',
            'matricule' => 'H003',
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'nom' => 'Autre Jean',
                'email' => 'jean.dup@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'sexe' => 'homme',
                'matricule' => 'H003',
                'estCommuniant' => true,
            ]);

        $response->assertStatus(409);
    }

    public function test_same_matricule_allowed_in_different_churches(): void
    {
        $church1 = Church::factory()->create();
        $church2 = Church::factory()->create();

        User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church1->id,
            'sexe' => 'homme',
            'matricule' => 'H004',
        ]);

        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church2->id,
            'actif' => true,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'nom' => 'Jean Autre Eglise',
                'email' => 'jean.autreeglise@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'sexe' => 'homme',
                'matricule' => 'H004',
                'estCommuniant' => true,
            ]);

        $response->assertStatus(201);
    }

    public function test_next_matricule_returns_next_available_for_sexe(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
            'actif' => true,
        ]);

        User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'sexe' => 'homme',
            'matricule' => 'H001',
        ]);
        User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'sexe' => 'homme',
            'matricule' => 'H002',
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users/next-matricule?sexe=homme');

        $response->assertStatus(200);
        $response->assertJsonPath('data.matricule', 'H003');
    }
}