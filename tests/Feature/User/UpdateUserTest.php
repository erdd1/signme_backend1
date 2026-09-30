
<?php


use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use App\Models\Quartier;
use App\Models\Ville;
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
        'is_super_admin' => true,
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

public function test_paroissien_can_update_own_safe_fields(): void
{
    $church = Church::factory()->create();
    $quartier = Quartier::factory()->create(['churchId' => $church->id]);
    $ville = Ville::factory()->create(['churchId' => $church->id]);

    $paroissien = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
    ]);

    $token = $paroissien->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$paroissien->id}", [
        'nom' => 'Mon Nouveau Nom',
        'telephone' => '699887766',
        'sexe' => 'homme',
        'dateNaissance' => '2000-01-01',
        'quartierId' => $quartier->id,
        'villeId' => $ville->id,
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $paroissien->id,
        'nom' => 'Mon Nouveau Nom',
        'telephone' => '699887766',
        'quartierResidenceId' => $quartier->id,
        'villeResidenceId' => $ville->id,
    ]);
}

public function test_paroissien_self_edit_silently_ignores_privileged_fields(): void
{
    $church = Church::factory()->create();

    $paroissien = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
        'email' => 'original@test.com',
    ]);

    $token = $paroissien->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$paroissien->id}", [
        'nom' => 'Nom Autorisé',
        'role' => 'administrateur',
        'email' => 'hacked@test.com',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $paroissien->id,
        'nom' => 'Nom Autorisé',
        'role' => 'paroissien',
        'email' => 'original@test.com',
    ]);
}

public function test_paroissien_cannot_update_another_member(): void
{
    $church = Church::factory()->create();

    $paroissien = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
    ]);

    $other = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
    ]);

    $token = $paroissien->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->putJson("/api/v1/users/{$other->id}", [
        'nom' => 'Tentative Modification',
    ]);

    $response->assertStatus(403);
}

}

