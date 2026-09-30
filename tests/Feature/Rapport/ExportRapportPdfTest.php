<?php

namespace Tests\Feature\Rapport;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ExportRapportPdfTest extends TestCase
{
    use RefreshDatabase;

    // ─── Rapport secrétaire ─────────────────────────────────────────────────

    public function test_pasteur_can_export_secretaire_pdf(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/rapports/secretaire/pdf?month='.now()->format('Y-m'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_secretaire_can_export_secretaire_pdf(): void
    {
        $church = Church::factory()->create();
        $secretaire = User::factory()->create(['role' => User::ROLE_SECRETAIRE, 'churchId' => $church->id]);

        $token = $secretaire->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/rapports/secretaire/pdf?month='.now()->format('Y-m'));

        $response->assertStatus(200);
    }

    public function test_tresorier_cannot_export_secretaire_pdf(): void
    {
        $church = Church::factory()->create();
        $tresorier = User::factory()->create(['role' => User::ROLE_TRESORIER, 'churchId' => $church->id]);

        $token = $tresorier->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/rapports/secretaire/pdf?month='.now()->format('Y-m'));

        $response->assertStatus(403);
    }

    public function test_secretaire_pdf_requires_month(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/rapports/secretaire/pdf');

        $response->assertStatus(422);
    }

    // ─── Rapport financier annuel ───────────────────────────────────────────

    public function test_pasteur_can_export_finances_annuel_pdf(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/rapports/finances/annuel/pdf');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_tresorier_can_export_finances_annuel_pdf(): void
    {
        $church = Church::factory()->create();
        $tresorier = User::factory()->create(['role' => User::ROLE_TRESORIER, 'churchId' => $church->id]);

        $token = $tresorier->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/rapports/finances/annuel/pdf?year='.now()->year);

        $response->assertStatus(200);
    }

    public function test_secretaire_cannot_export_finances_annuel_pdf(): void
    {
        $church = Church::factory()->create();
        $secretaire = User::factory()->create(['role' => User::ROLE_SECRETAIRE, 'churchId' => $church->id]);

        $token = $secretaire->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/rapports/finances/annuel/pdf');

        $response->assertStatus(403);
    }
}
