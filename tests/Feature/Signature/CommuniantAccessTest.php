<?php

namespace Tests\Feature\Signature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CommuniantAccessTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_NOT_COMMUNIANT =
        "Vous ne pouvez pas avoir accès à cette fonctionnalité car vous n'êtes pas communiant.";

    public function test_non_communiant_paroissien_cannot_initiate_distance_signature(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/signatures/distance', [
                'months' => [now()->format('Y-m')],
                'amount' => 1000,
                'paymentMethod' => 'mobile_money',
                'paymentOperator' => 'ORANGE MONEY',
                'phoneNumber' => '699803165',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', self::MSG_NOT_COMMUNIANT);
    }

    public function test_non_communiant_paroissien_cannot_be_signed_presentiel(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
        ]);
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/signatures/presentiel', [
                'userId' => $paroissien->id,
                'months' => [now()->format('Y-m')],
                'amount' => 1000,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', self::MSG_NOT_COMMUNIANT);
    }

    public function test_non_communiant_paroissien_cannot_view_ma_carte(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/signatures/ma-carte');

        $response->assertStatus(403);
        $response->assertJsonPath('message', self::MSG_NOT_COMMUNIANT);
    }

    public function test_communiant_paroissien_can_view_ma_carte(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => true,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/signatures/ma-carte');

        $response->assertStatus(200);
    }

    public function test_non_communiant_paroissien_cannot_view_historique_personnel(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/signatures/historique-personnel');

        $response->assertStatus(403);
        $response->assertJsonPath('message', self::MSG_NOT_COMMUNIANT);
    }

    public function test_non_communiant_paroissien_cannot_view_historique_personnel_presentiel(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/signatures/historique-personnel-presentiel');

        $response->assertStatus(403);
        $response->assertJsonPath('message', self::MSG_NOT_COMMUNIANT);
    }

    public function test_becoming_communiant_sets_floor_month_and_blocks_past_signing(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
        ]);
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $update = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/users/{$paroissien->id}", [
                'estCommuniant' => true,
            ]);
        $update->assertStatus(200);

        $paroissien->refresh();
        $this->assertTrue((bool) $paroissien->estCommuniant);
        $this->assertSame(now()->startOfMonth()->format('Y-m-d'), $paroissien->communiantDepuis->format('Y-m-d'));

        // Mois passé (avant le déblocage) : bloqué, jamais de rattrapage.
        $pastMonth = now()->subMonth()->format('Y-m');
        $pastAttempt = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/signatures/presentiel', [
                'userId' => $paroissien->id,
                'months' => [$pastMonth],
                'amount' => 1000,
            ]);
        $pastAttempt->assertStatus(400);

        // Mois courant (à partir du déblocage) : autorisé.
        $currentAttempt = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/signatures/presentiel', [
                'userId' => $paroissien->id,
                'months' => [now()->format('Y-m')],
                'amount' => 1000,
            ]);
        $currentAttempt->assertStatus(201);
    }

    public function test_role_change_away_from_paroissien_forces_communiant_true(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
        ]);
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/users/{$paroissien->id}", [
                'role' => User::ROLE_ANCIEN,
            ]);
        $response->assertStatus(200);

        $paroissien->refresh();
        $this->assertTrue((bool) $paroissien->estCommuniant);
        $this->assertNull($paroissien->communiantDepuis);
    }

    public function test_non_paroissien_cannot_be_set_non_communiant_on_create(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'nom' => 'Tresorier Test',
                'email' => 'tresorier.communiant@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_TRESORIER,
                'sexe' => 'homme',
                'matricule' => 'H001',
                'estCommuniant' => false,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'tresorier.communiant@test.com',
            'estCommuniant' => true,
        ]);
    }
}
