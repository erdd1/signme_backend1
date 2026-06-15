
<?php


use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpdateUserTest extends TestCase
{
    use RefreshDatabase;

public function test_pasteur_can_update_paroissien_name(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $paroissien = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$paroissien->id}", [
        'nom' => 'Nouveau Nom',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $paroissien->id,
        'nom' => 'Nouveau Nom',
    ]);
}

public function test_email_must_be_unique_when_updating(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $user1 = User::factory()->create([
        'email' => 'user1@test.com',
        'churchId' => $church->id,
    ]);

    $user2 = User::factory()->create([
        'email' => 'user2@test.com',
        'churchId' => $church->id,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$user2->id}", [
        'email' => 'user1@test.com',
    ]);

    $response->assertStatus(422);
}

public function test_secretaire_cannot_change_role(): void
{
    $church = Church::factory()->create();

    $secretaire = User::factory()->create([
        'role' => 'secretaire',
        'churchId' => $church->id,
    ]);

    $user = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
    ]);

    $token = $secretaire->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$user->id}", [
        'role' => 'ancien',
    ]);

    $response->assertStatus(403);
}

public function test_pasteur_cannot_assign_admin_role(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $user = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$user->id}", [
        'role' => 'administrateur',
    ]);

    $response->assertStatus(403);
}

public function test_pasteur_cannot_assign_pasteur_role(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $user = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$user->id}", [
        'role' => 'pasteur',
    ]);

    $response->assertStatus(403);
}

public function test_pasteur_cannot_update_user_from_another_church(): void
{
    $church1 = Church::factory()->create();

    $church2 = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church1->id,
    ]);

    $target = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church2->id,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$target->id}", [
        'nom' => 'Tentative Modification',
    ]);

    $response->assertStatus(403);
}

}

