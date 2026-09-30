<?php

namespace Tests\Feature\User;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ToggleStatusTest extends TestCase
{

use RefreshDatabase;

public function test_admin_can_disable_user(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $user = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
        'actif' => true,
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->patchJson("/api/admin/users/{$user->id}/toggle-status");

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'actif' => false,
    ]);
}

public function test_admin_can_enable_user(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $user = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church->id,
        'actif' => false,
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->patchJson("/api/admin/users/{$user->id}/toggle-status");

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'actif' => true,
    ]);
}

public function test_pasteur_cannot_disable_admin(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church->id,
    ]);

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->patchJson("/api/v1/users/{$admin->id}/toggle-status");

    $response->assertStatus(403);
}

public function test_disabling_user_revokes_all_access_tokens(): void
{
    $church = Church::factory()->create();

    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
    ]);

    $user = User::factory()->create([
        'churchId' => $church->id,
        'actif' => true,
    ]);

    $user->createToken('device1');
    $user->createToken('device2');
    $user->createToken('device3');

    $this->assertEquals(
        3,
        $user->tokens()->count()
    );

    $token = $admin->createToken('admin')->plainTextToken;

    $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->patchJson("/api/admin/users/{$user->id}/toggle-status");;

    $this->assertEquals(
        0,
        $user->fresh()->tokens()->count()
    );
}

public function test_pasteur_cannot_toggle_user_from_another_church(): void
{
    $church1 = Church::factory()->create();
    $church2 = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => 'pasteur',
        'churchId' => $church1->id,
    ]);

    $user = User::factory()->create([
        'role' => 'paroissien',
        'churchId' => $church2->id,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->patchJson("/api/v1/users/{$user->id}/toggle-status");

    $response->assertStatus(403);
}

public function test_admin_cannot_disable_himself(): void
{
    $admin = User::factory()->create([
        'role' => 'administrateur',
        'is_super_admin' => true,
        'actif' => true,
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer ' . $token
    )->patchJson(
        "/api/admin/users/{$admin->id}/toggle-status"
    );

    $response->assertStatus(403);

    $this->assertDatabaseHas('users', [
        'id' => $admin->id,
        'actif' => true,
    ]);
}

}