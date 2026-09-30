<?php
namespace Tests\Feature\Church;
use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AssignPastorTest extends TestCase
{
    use RefreshDatabase;

public function test_admin_can_assign_pastor(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $pastor = User::factory()->create([
        'role' => 'pasteur',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", [
        'pastor_id' => $pastor->id,
    ]);

    $response->assertStatus(200);
}

public function test_assign_pastor_updates_church_pastor_id(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $pastor = User::factory()->create([
        'role' => 'pasteur',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", [
        'pastor_id' => $pastor->id,
    ]);

    $this->assertDatabaseHas('churches', [
        'id' => $church->id,
        'pastorId' => $pastor->id,
    ]);
}

public function test_assign_pastor_updates_church_pastor_name(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $pastor = User::factory()->create([
        'role' => 'pasteur',
        'nom' => 'Pasteur Paul',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", [
        'pastor_id' => $pastor->id,
    ]);

    $this->assertDatabaseHas('churches', [
        'id' => $church->id,
        'pastorNom' => 'Pasteur Paul',
    ]);
}

public function test_assign_pastor_updates_pastor_church(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $pastor = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => null,
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", [
        'pastor_id' => $pastor->id,
    ]);

    $this->assertDatabaseHas('users', [
        'id' => $pastor->id,
        'churchId' => $church->id,
    ]);
}

public function test_pastor_id_is_required(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", []);

    $response->assertStatus(422);
}

public function test_pastor_must_exist(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", [
        'pastor_id' => 999999,
    ]);

    $response->assertStatus(422);
}

public function test_church_must_exist(): void
{
    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $pastor = User::factory()->create([
        'role' => 'pasteur',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/999999/pastor", [
        'pastor_id' => $pastor->id,
    ]);

    $response->assertStatus(404);
}

public function test_user_must_be_pastor(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $user = User::factory()->create([
        'role' => 'paroissien',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", [
        'pastor_id' => $user->id,
    ]);

    $response->assertStatus(400);
}

public function test_guest_cannot_assign_pastor(): void
{
    $church = Church::factory()->create();

    $pastor = User::factory()->create([
        'role' => 'pasteur',
    ]);

    $response = $this->postJson(
        "/api/admin/churches/{$church->id}/pastor",
        [
            'pastor_id' => $pastor->id,
        ]
    );

    $response->assertStatus(401);
}

public function test_pasteur_cannot_assign_pastor(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
    ]);

    $otherPastor = User::factory()->create([
        'role' => 'pasteur',
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson("/api/admin/churches/{$church->id}/pastor", [
        'pastor_id' => $otherPastor->id,
    ]);

    $response->assertStatus(403);
}

}