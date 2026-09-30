<?php

namespace Tests\Feature\Church;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreateChurchTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_is_required(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/churches', [
            'ville' => 'Yaounde',
        ]);

        $response->assertStatus(422);
    }

    public function test_city_is_required(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/churches', [
            'nom' => 'EEC Messamedongo',
        ]);

        $response->assertStatus(422);
    }

    public function test_email_must_be_valid(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/churches', [
            'nom'   => 'EEC Messamedongo',
            'ville' => 'Yaounde',
            'email' => 'email-invalide',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_create_church(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/churches', [
            'nom'   => 'EEC Messamedongo',
            'ville' => 'Yaounde',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('churches', [
            'nom'   => 'EEC Messamedongo',
            'ville' => 'Yaounde',
        ]);
    }

    public function test_duplicate_church_in_same_city_is_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        Church::create([
            'nom'   => 'EEC Messamedongo',
            'ville' => 'Yaounde',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/churches', [
            'nom'   => 'EEC Messamedongo',
            'ville' => 'Yaounde',
        ]);

        $response->assertStatus(409);
    }

    public function test_same_name_in_different_city_is_allowed(): void
    {
        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        Church::create([
            'nom'   => 'EEC Messamedongo',
            'ville' => 'Yaounde',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/churches', [
            'nom'   => 'EEC Messamedongo',
            'ville' => 'Douala',
        ]);

        $response->assertStatus(201);
    }

    public function test_guest_cannot_create_church(): void
    {
    $response = $this->postJson('/api/admin/churches', [
        'nom' => 'EEC Messamedongo',
        'ville' => 'Yaounde',
    ]);

    $response->assertStatus(401);
    }

    public function test_pasteur_cannot_create_church(): void
{
    $pasteur = User::factory()->create([
        'role' => 'pasteur',
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson('/api/admin/churches', [
        'nom' => 'EEC Messamedongo',
        'ville' => 'Yaounde',
    ]);

    $response->assertStatus(403);
}

public function test_secretaire_cannot_create_church(): void
{
    $user = User::factory()->create([
        'role' => 'secretaire',
    ]);

    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson('/api/admin/churches', [
        'nom' => 'EEC Messamedongo',
        'ville' => 'Yaounde',
    ]);

    $response->assertStatus(403);
}

}
