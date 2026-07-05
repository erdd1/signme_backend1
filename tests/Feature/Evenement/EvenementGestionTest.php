<?php

namespace Tests\Feature\Evenement;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvenementGestionTest extends TestCase
{
    use RefreshDatabase;

    private function pasteur(Church $church): User
    {
        return User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
    }

    private function evenement(Church $church, User $auteur, string $statut, array $overrides = []): Evenement
    {
        return Evenement::create(array_merge([
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Excellence scolaire 2026',
            'description' => str_repeat('a', 25),
            'statut'      => $statut,
            'dateDebut'   => now()->subDays(10),
        ], $overrides));
    }

    public function test_gestion_shows_both_actif_and_archive_events(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $actif = $this->evenement($church, $auteur, Evenement::STATUT_ACTIF, ['publishedAt' => now()]);
        $archive = $this->evenement($church, $auteur, Evenement::STATUT_ARCHIVE, ['archivedAt' => now()]);

        $token = $auteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/evenements/gestion');

        $response->assertStatus(200);
        $uuids = collect($response->json('data'))->pluck('uuid')->all();
        $this->assertContains($actif->uuid, $uuids);
        $this->assertContains($archive->uuid, $uuids);
    }

    public function test_gestion_can_filter_by_statut(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $this->evenement($church, $auteur, Evenement::STATUT_ACTIF, ['publishedAt' => now()]);
        $archive = $this->evenement($church, $auteur, Evenement::STATUT_ARCHIVE, ['archivedAt' => now()]);

        $token = $auteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/evenements/gestion?statut=archive');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($archive->uuid, $data[0]['uuid']);
    }

    public function test_gestion_archives_tab_also_includes_termine_events(): void
    {
        // Onglet "Archivés" de l'écran de gestion = statuts termine + archive
        // (un événement terminé doit y apparaître même s'il n'a pas encore
        // quitté le fil d'actualité via l'archivage automatique 3 jours après).
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $actif = $this->evenement($church, $auteur, Evenement::STATUT_ACTIF, ['publishedAt' => now()]);
        $termine = $this->evenement($church, $auteur, Evenement::STATUT_TERMINE, ['termineAt' => now()]);
        $archive = $this->evenement($church, $auteur, Evenement::STATUT_ARCHIVE, ['archivedAt' => now()]);

        $token = $auteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/evenements/gestion?statut=termine,archive');

        $response->assertStatus(200);
        $uuids = collect($response->json('data'))->pluck('uuid')->all();
        $this->assertContains($termine->uuid, $uuids);
        $this->assertContains($archive->uuid, $uuids);
        $this->assertNotContains($actif->uuid, $uuids);
    }

    public function test_gestion_computes_montant_collecte_and_distinct_contributeurs(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $donateur1 = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $donateur2 = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $evt = $this->evenement($church, $auteur, Evenement::STATUT_ACTIF, [
            'publishedAt' => now(),
            'montantCollecte' => 1700,
            'nombreContributions' => 3,
        ]);

        // donateur1 contribue deux fois (2 contributions, 1 seul contributeur distinct)
        Contribution::create([
            'uuid' => \Illuminate\Support\Str::uuid(), 'evenementId' => $evt->id, 'userId' => $donateur1->id,
            'montant' => 500, 'frais' => 50, 'montantTotal' => 550,
            'paymentOperator' => 'ORANGE MONEY', 'paymentNumber' => '699112233',
            'statut' => Contribution::STATUT_COMPLETED,
        ]);
        Contribution::create([
            'uuid' => \Illuminate\Support\Str::uuid(), 'evenementId' => $evt->id, 'userId' => $donateur1->id,
            'montant' => 700, 'frais' => 50, 'montantTotal' => 750,
            'paymentOperator' => 'ORANGE MONEY', 'paymentNumber' => '699112233',
            'statut' => Contribution::STATUT_COMPLETED,
        ]);
        Contribution::create([
            'uuid' => \Illuminate\Support\Str::uuid(), 'evenementId' => $evt->id, 'userId' => $donateur2->id,
            'montant' => 500, 'frais' => 50, 'montantTotal' => 550,
            'paymentOperator' => 'ORANGE MONEY', 'paymentNumber' => '699112233',
            'statut' => Contribution::STATUT_COMPLETED,
        ]);
        // Contribution pending : ne doit pas compter dans les contributeurs distincts
        Contribution::create([
            'uuid' => \Illuminate\Support\Str::uuid(), 'evenementId' => $evt->id, 'userId' => $donateur2->id,
            'montant' => 1000, 'frais' => 100, 'montantTotal' => 1100,
            'paymentOperator' => 'ORANGE MONEY', 'paymentNumber' => '699112233',
            'statut' => Contribution::STATUT_PENDING,
        ]);

        $token = $auteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/evenements/gestion');

        $response->assertStatus(200);
        $data = collect($response->json('data'))->firstWhere('uuid', $evt->uuid);
        $this->assertSame(1700, $data['montantCollecte']);
        $this->assertSame(2, $data['nombreContributeurs']);
    }

    public function test_paroissien_cannot_access_gestion(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/evenements/gestion');

        $response->assertStatus(403);
    }

    public function test_tresorier_can_access_gestion_read_only(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $this->evenement($church, $auteur, Evenement::STATUT_ARCHIVE, ['archivedAt' => now()]);

        $tresorier = User::factory()->create(['role' => 'tresorier', 'churchId' => $church->id]);
        $token = $tresorier->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/evenements/gestion');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        // Le trésorier ne peut pas gérer (créer/modifier), juste consulter.
        $this->assertFalse($response->json('data.0.peutGerer'));
    }

    public function test_guest_cannot_access_gestion(): void
    {
        $response = $this->getJson('/api/v1/evenements/gestion');
        $response->assertStatus(401);
    }
}
