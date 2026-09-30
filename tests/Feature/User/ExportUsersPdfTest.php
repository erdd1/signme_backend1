<?php

namespace Tests\Feature\User;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ExportUsersPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasteur_can_export_users_pdf(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id, 'sexe' => 'homme', 'matricule' => 'H001']);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/users/export-pdf');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_secretaire_can_export_users_pdf(): void
    {
        $church = Church::factory()->create();
        $secretaire = User::factory()->create(['role' => User::ROLE_SECRETAIRE, 'churchId' => $church->id]);

        $token = $secretaire->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/users/export-pdf');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_tresorier_cannot_export_users_pdf(): void
    {
        $church = Church::factory()->create();
        $tresorier = User::factory()->create(['role' => User::ROLE_TRESORIER, 'churchId' => $church->id]);

        $token = $tresorier->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/users/export-pdf');

        $response->assertStatus(403);
    }

    public function test_paroissien_cannot_export_users_pdf(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/users/export-pdf');

        $response->assertStatus(403);
    }

    public function test_guest_cannot_export_users_pdf(): void
    {
        $response = $this->getJson('/api/v1/users/export-pdf');

        $response->assertStatus(401);
    }
}
