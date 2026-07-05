<?php

namespace Tests\Feature\Evenement;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MesContributionsTest extends TestCase
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

    private function contribution(Evenement $evenement, User $user, int $montant, string $statut, ?Carbon $createdAt = null): Contribution
    {
        $contribution = Contribution::create([
            'uuid'            => \Illuminate\Support\Str::uuid(),
            'evenementId'     => $evenement->id,
            'userId'          => $user->id,
            'montant'         => $montant,
            'frais'           => 50,
            'montantTotal'    => $montant + 50,
            'paymentOperator' => 'ORANGE MONEY',
            'paymentNumber'   => '699112233',
            'statut'          => $statut,
        ]);

        if ($createdAt) {
            $contribution->created_at = $createdAt;
            $contribution->save();
        }

        return $contribution;
    }

    public function test_only_sees_own_contributions(): void
    {
        $church = Church::factory()->create();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $moi = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $autre = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->evenement($church, $auteur);

        $this->contribution($evenement, $moi, 700, Contribution::STATUT_COMPLETED);
        $this->contribution($evenement, $autre, 1000, Contribution::STATUT_COMPLETED);

        $token = $moi->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/mes-contributions');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(700, $data[0]['montant']);
    }

    /**
     * L'utilisateur a déjà vu le résultat de sa tentative (réussie, échouée
     * ou en attente) via l'écran de confirmation du paiement — l'historique
     * "Mes dépenses" ne doit montrer que les paiements réellement reçus.
     * Les statuts pending/failed restent visibles côté trésorerie (pasteur/
     * secrétaire/trésorier), pas ici.
     */
    public function test_pending_and_failed_contributions_are_never_shown(): void
    {
        $church = Church::factory()->create();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $moi = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->evenement($church, $auteur);

        $this->contribution($evenement, $moi, 700, Contribution::STATUT_COMPLETED);
        $this->contribution($evenement, $moi, 1500, Contribution::STATUT_PENDING);
        $this->contribution($evenement, $moi, 3000, Contribution::STATUT_FAILED);

        $token = $moi->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/mes-contributions');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(700, $data[0]['montant']);
    }

    public function test_stats_total_ce_mois_cette_annee(): void
    {
        $church = Church::factory()->create();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $moi = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->evenement($church, $auteur);

        // Ce mois-ci (compte dans total, ce mois, cette année)
        $this->contribution($evenement, $moi, 1000, Contribution::STATUT_COMPLETED, now());

        // Cette année mais un autre mois (compte dans total et cette année, pas ce mois)
        $this->contribution($evenement, $moi, 2000, Contribution::STATUT_COMPLETED, now()->subMonths(2));

        // L'année dernière (compte seulement dans le total à vie)
        $this->contribution($evenement, $moi, 5000, Contribution::STATUT_COMPLETED, now()->subYear());

        // Pending : ne doit compter dans aucune statistique
        $this->contribution($evenement, $moi, 10000, Contribution::STATUT_PENDING, now());

        $token = $moi->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/mes-contributions/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.totalDonne', 8000);
        $response->assertJsonPath('data.cetteAnnee', 3000);
        $response->assertJsonPath('data.ceMois', 1000);
    }

    public function test_future_year_filter_is_rejected(): void
    {
        $church = Church::factory()->create();
        $moi = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $token = $moi->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/mes-contributions?annee=' . (now()->year + 1));

        $response->assertStatus(400);
    }

    public function test_guest_cannot_access_mes_contributions(): void
    {
        $response = $this->getJson('/api/v1/mes-contributions');
        $response->assertStatus(401);
    }
}
