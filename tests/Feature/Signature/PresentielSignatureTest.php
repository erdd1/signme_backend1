<?php

namespace Tests\Feature\Signature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use App\Models\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PresentielSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_id_is_required(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $response->assertStatus(422);
    }

    public function test_months_are_required(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'amount' => 1000,
        ]);

        $response->assertStatus(422);
    }

    public function test_amount_is_required(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
        ]);

        $response->assertStatus(422);
    }

    public function test_amount_must_be_at_least_100(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
            'amount' => 50,
        ]);

        $response->assertStatus(422);
    }

    public function test_pasteur_can_sign_presentiel(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $response->assertStatus(201);
    }

    public function test_admin_can_sign_presentiel(): void
    {
        $church = Church::factory()->create();

        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $response->assertStatus(201);
    }

    public function test_paroissien_cannot_sign_presentiel(): void
    {
        $church = Church::factory()->create();

        $paroissien = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_sign_presentiel(): void
    {
        $response = $this->postJson('/api/v1/signatures/presentiel', [
            'userId' => 1,
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $response->assertStatus(401);
    }

    public function test_signature_is_created_as_completed(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $this->assertDatabaseHas('signatures', [
            'userId' => $user->id,
            'status' => 'completed',
            'signatureType' => 'presentiel',
        ]);
    }

    public function test_multiple_months_create_multiple_signatures(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [
                now()->subMonths(2)->format('Y-m'),
                now()->subMonths(1)->format('Y-m'),
                now()->format('Y-m'),
            ],
            'amount' => 1000,
        ]);

        $this->assertEquals(
            3,
            Signature::count()
        );
    }

    public function test_pasteur_cannot_sign_user_from_another_church(): void
    {
        $church1 = Church::factory()->create();
        $church2 = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church1->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church2->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_sign_user_from_another_church(): void
    {
        $church1 = Church::factory()->create();
        $church2 = Church::factory()->create();

        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
            'churchId' => $church1->id,
        ]);

        $user = User::factory()->create([
            'churchId' => $church2->id,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/signatures/presentiel', [
            'userId' => $user->id,
            'months' => [now()->format('Y-m')],
            'amount' => 1000,
        ]);

        $response->assertStatus(201);
    }


    public function test_cannot_sign_same_month_twice(): void
{
    $church = Church::factory()->create();

    $pasteur = User::factory()->create([
        'role' => User::ROLE_PASTEUR,
        'churchId' => $church->id,
    ]);

    $user = User::factory()->create([
        'churchId' => $church->id,
    ]);

    $month = now()->subMonth()->format('Y-m');

    Signature::create([
        'userId' => $user->id,
        'nomParoissien' => $user->nom,
        'churchId' => $church->id,
        'monthIndex' => 0,
        'month' => $month,
        'year' => now()->year,
        'amount' => 1000,
        'fees' => 0,
        'paymentMethod' => 'ESPECES',
        'signatureType' => 'presentiel',
        'status' => 'completed',
        'ancienId' => $pasteur->id,
        'ancienNom' => $pasteur->nom,
    ]);

    $token = $pasteur->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson('/api/v1/signatures/presentiel', [
        'userId' => $user->id,
        'months' => [$month],
        'amount' => 1000,
    ]);

    $response->assertStatus(409);
}
}