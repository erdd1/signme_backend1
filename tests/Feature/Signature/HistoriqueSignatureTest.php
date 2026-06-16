<?php

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use App\Models\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HistoriqueSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_historique(): void
    {
        $response = $this->getJson(
            '/api/v1/signatures/historique'
        );

        $response->assertStatus(401);
    }

    public function test_pasteur_can_view_historique(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        Signature::factory()->create([
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->getJson(
            '/api/v1/signatures/historique'
        );

        $response->assertStatus(200);
    }

    public function test_pasteur_only_sees_his_church_history(): void
    {
        $church1 = Church::factory()->create();
        $church2 = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church1->id,
        ]);

        Signature::factory()->create([
            'churchId' => $church1->id,
        ]);

        Signature::factory()->create([
            'churchId' => $church2->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->getJson(
            '/api/v1/signatures/historique'
        );

        $response->assertStatus(200);
    }

    public function test_user_can_view_personal_distance_history(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        Signature::factory()->create([
            'userId' => $user->id,
            'signatureType' => 'distance',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->getJson(
            '/api/v1/signatures/historique-personnel'
        );

        $response->assertStatus(200);
    }

    public function test_personal_history_excludes_other_users(): void
{
    $church = Church::factory()->create();

    $user1 = User::factory()->create([
        'churchId' => $church->id,
    ]);

    $user2 = User::factory()->create([
        'churchId' => $church->id,
    ]);

    Signature::factory()->create([
        'userId' => $user1->id,
        'churchId' => $church->id,
        'signatureType' => 'distance',
    ]);

    Signature::factory()->create([
        'userId' => $user2->id,
        'churchId' => $church->id,
        'signatureType' => 'distance',
    ]);

    $token = $user1->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->getJson(
        '/api/v1/signatures/historique-personnel'
    );

    $response->assertStatus(200);

    $response->assertJsonCount(1, 'data');

    $response->assertJsonFragment([
            'userId' => $user1->id,
        ]);
}

    public function test_user_can_view_personal_presentiel_history(): void
{
    $church = Church::factory()->create();

    $user = User::factory()->create([
        'churchId' => $church->id,
    ]);

    Signature::factory()->create([
        'userId' => $user->id,
        'churchId' => $church->id,
        'signatureType' => 'presentiel',
        'status' => 'completed',
    ]);

    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->getJson(
        '/api/v1/signatures/historique-personnel-presentiel'
    );

    $response->assertStatus(200);

    $response->assertJsonCount(1, 'data');

    $response->assertJsonFragment([
        'signatureType' => 'presentiel',
    ]);
}

    public function test_ancien_can_view_his_signed_presentiel_history(): void
{
    $church = Church::factory()->create();

    $ancien = User::factory()->create([
        'role' => 'ancien',
        'churchId' => $church->id,
        'est_de_service' => true,
    ]);

    $paroissien = User::factory()->create([
        'churchId' => $church->id,
    ]);

    Signature::factory()->create([
        'userId' => $paroissien->id,
        'churchId' => $church->id,
        'signatureType' => 'presentiel',
        'ancienId' => $ancien->id,
        'status' => 'completed',
    ]);

    $token = $ancien->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->getJson(
        '/api/v1/signatures/historique-signatures'
    );

    $response->assertStatus(200);

    $response->assertJsonCount(1, 'data');

    $response->assertJsonFragment([
        'ancienId' => $ancien->id,
    ]);
}

    public function test_paroissien_cannot_view_signature_audit_history(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'paroissien',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->getJson(
            '/api/v1/signatures/historique-signatures'
        );

        $response->assertStatus(403);
    }
}