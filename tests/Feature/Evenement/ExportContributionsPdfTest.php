<?php

namespace Tests\Feature\Evenement;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportContributionsPdfTest extends TestCase
{
    use RefreshDatabase;

    private function evenement(Church $church, User $auteur): Evenement
    {
        return Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Construction du temple',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);
    }

    private function contribution(Evenement $evenement, User $user, int $montant): Contribution
    {
        return Contribution::create([
            'uuid'            => \Illuminate\Support\Str::uuid(),
            'evenementId'     => $evenement->id,
            'userId'          => $user->id,
            'montant'         => $montant,
            'frais'           => 50,
            'montantTotal'    => $montant + 50,
            'paymentOperator' => 'ORANGE MONEY',
            'paymentNumber'   => '699112233',
            'statut'          => Contribution::STATUT_COMPLETED,
        ]);
    }

    public function test_pasteur_can_export_contributions_pdf(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $contributeur = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);
        $evenement = $this->evenement($church, $pasteur);
        $this->contribution($evenement, $contributeur, 1000);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/contributions-evenements/export-pdf');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_tresorier_can_export_contributions_pdf(): void
    {
        $church = Church::factory()->create();
        $tresorier = User::factory()->create(['role' => User::ROLE_TRESORIER, 'churchId' => $church->id]);

        $token = $tresorier->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/contributions-evenements/export-pdf');

        $response->assertStatus(200);
    }

    public function test_secretaire_cannot_export_contributions_pdf(): void
    {
        // Le secrétaire garde l'accès en lecture au tableau de bord, mais pas
        // à cet export — distinction explicitement demandée.
        $church = Church::factory()->create();
        $secretaire = User::factory()->create(['role' => User::ROLE_SECRETAIRE, 'churchId' => $church->id]);

        $token = $secretaire->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/contributions-evenements/export-pdf');

        $response->assertStatus(403);

        // Confirme que le secrétaire voit bien les autres endpoints du même
        // tableau de bord (la restriction est spécifique à l'export).
        $viewResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/contributions-evenements/stats');
        $viewResponse->assertStatus(200);
    }

    public function test_export_pdf_filters_by_event(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $contributeur = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);
        $evenement1 = $this->evenement($church, $pasteur);
        $evenement2 = $this->evenement($church, $pasteur);
        $this->contribution($evenement1, $contributeur, 1000);
        $this->contribution($evenement2, $contributeur, 2000);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/contributions-evenements/export-pdf?event_id='.$evenement1->id);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }
}
