<?php

namespace Tests\Feature\User;

use App\Models\Church;
use App\Models\Groupe;
use App\Models\Quartier;
use App\Models\User;
use App\Models\Ville;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDemographicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasteur_can_create_user_with_demographic_fields(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $quartier = Quartier::factory()->create(['churchId' => $church->id]);
        $ville = Ville::factory()->create(['churchId' => $church->id, 'estDiaspora' => false]);
        $groupe1 = Groupe::factory()->create(['churchId' => $church->id]);
        $groupe2 = Groupe::factory()->create(['churchId' => $church->id]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'nom' => 'Jean Dupont',
                'email' => 'jean.demo@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'sexe' => 'homme',
                'matricule' => 'H001',
                'estCommuniant' => true,
                'dateNaissance' => '1995-05-10',
                'originaireDe' => 'Centre',
                'quartierId' => $quartier->id,
                'villeId' => $ville->id,
                'groupeIds' => [$groupe1->id, $groupe2->id],
            ]);

        $response->assertStatus(201);

        $user = User::where('email', 'jean.demo@test.com')->first();
        $this->assertSame('homme', $user->sexe);
        $this->assertSame('1995-05-10', $user->dateNaissance->format('Y-m-d'));
        $this->assertSame('Centre', $user->originaireDe);
        $this->assertSame($quartier->id, $user->quartierResidenceId);
        $this->assertSame($ville->id, $user->villeResidenceId);
        $this->assertCount(2, $user->groupes);

        $response->assertJsonPath('data.sexe', 'homme');
        $response->assertJsonPath('data.quartier.id', $quartier->id);
        $response->assertJsonPath('data.ville.estDiaspora', false);
        $response->assertJsonMissingPath('data.groupePrincipal');
    }

    public function test_originaire_de_must_be_a_valid_region(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'nom' => 'Jean Dupont',
                'email' => 'jean.region@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'originaireDe' => 'Paris',
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_assign_quartier_from_another_church(): void
    {
        $church = Church::factory()->create();
        $autreChurch = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $quartierAutreEglise = Quartier::factory()->create(['churchId' => $autreChurch->id]);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'nom' => 'Jean Dupont',
                'email' => 'jean.crosschurch@test.com',
                'password' => 'Password123',
                'role' => User::ROLE_PAROISSIEN,
                'estCommuniant' => true,
                'quartierId' => $quartierAutreEglise->id,
            ]);

        $response->assertStatus(400);
    }

    public function test_update_resyncs_groupes(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $groupe1 = Groupe::factory()->create(['churchId' => $church->id]);
        $groupe2 = Groupe::factory()->create(['churchId' => $church->id]);

        $membre = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);
        $membre->groupes()->attach($groupe1->id);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/users/{$membre->id}", [
                'groupeIds' => [$groupe2->id],
            ]);

        $response->assertStatus(200);

        $membre->refresh();
        $this->assertCount(1, $membre->groupes);
        $this->assertSame($groupe2->id, $membre->groupes->first()->id);
    }

    public function test_list_filters_by_sexe_and_mois_naissance(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);

        User::factory()->create([
            'role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id,
            'sexe' => 'homme', 'dateNaissance' => '1990-03-15',
        ]);
        User::factory()->create([
            'role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id,
            'sexe' => 'femme', 'dateNaissance' => '1990-07-20',
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $bySexe = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users?sexe=femme');
        $bySexe->assertStatus(200)->assertJsonCount(1, 'data');

        $byMois = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users?moisNaissance=3');
        $byMois->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_list_filters_by_quartier_groupe_and_originaire_de(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $quartier = Quartier::factory()->create(['churchId' => $church->id]);
        $groupe = Groupe::factory()->create(['churchId' => $church->id]);

        $membre = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id,
            'quartierResidenceId' => $quartier->id, 'originaireDe' => 'Ouest',
        ]);
        $membre->groupes()->attach($groupe->id);

        User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $byQuartier = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/users?quartierId={$quartier->id}");
        $byQuartier->assertStatus(200)->assertJsonCount(1, 'data');

        $byGroupe = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/users?groupeId={$groupe->id}");
        $byGroupe->assertStatus(200)->assertJsonCount(1, 'data');

        $byRegion = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users?originaireDe=Ouest');
        $byRegion->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_stats_returns_totals_by_sexe_and_residence(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $villeCameroun = Ville::factory()->create(['churchId' => $church->id, 'estDiaspora' => false]);
        $villeDiaspora = Ville::factory()->create(['churchId' => $church->id, 'estDiaspora' => true]);

        User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id, 'sexe' => 'homme', 'villeResidenceId' => $villeCameroun->id]);
        User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id, 'sexe' => 'femme', 'villeResidenceId' => $villeDiaspora->id]);
        User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id, 'sexe' => 'femme']);

        $token = $pasteur->createToken('test')->plainTextToken;

        // +1 pour le pasteur lui-même (même église, sans sexe/ville renseignés)
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_fideles', 4);
        $response->assertJsonPath('data.hommes', 1);
        $response->assertJsonPath('data.femmes', 2);
        $response->assertJsonPath('data.au_cameroun', 1);
        $response->assertJsonPath('data.hors_cameroun', 1);
    }

    public function test_paroissien_cannot_access_stats(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);
        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users/stats');

        $response->assertStatus(403);
    }
}
