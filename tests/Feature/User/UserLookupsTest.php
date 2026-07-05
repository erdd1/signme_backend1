<?php

namespace Tests\Feature\User;

use App\Models\Church;
use App\Models\Groupe;
use App\Models\Quartier;
use App\Models\User;
use App\Models\Ville;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLookupsTest extends TestCase
{
    use RefreshDatabase;

    private function actingPasteur(Church $church): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PASTEUR,
            'churchId' => $church->id,
        ]);
    }

    // ─── Groupes ────────────────────────────────────────────────────────────

    public function test_pasteur_can_create_and_list_groupes(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->actingPasteur($church);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/groupes', ['nom' => 'Chorale']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('groupes', ['nom' => 'Chorale', 'churchId' => $church->id]);

        $list = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/groupes');

        $list->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_paroissien_cannot_create_groupe(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);
        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/groupes', ['nom' => 'Chorale']);

        $response->assertStatus(403);
    }

    public function test_paroissien_can_read_groupes(): void
    {
        $church = Church::factory()->create();
        Groupe::factory()->create(['churchId' => $church->id, 'nom' => 'Jeunesse']);

        $paroissien = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);
        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/groupes');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_duplicate_groupe_name_in_same_church_is_rejected(): void
    {
        $church = Church::factory()->create();
        Groupe::factory()->create(['churchId' => $church->id, 'nom' => 'Chorale']);

        $pasteur = $this->actingPasteur($church);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/groupes', ['nom' => 'Chorale']);

        $response->assertStatus(409);
    }

    public function test_groupe_scoping_is_isolated_per_church(): void
    {
        $church1 = Church::factory()->create();
        $church2 = Church::factory()->create();
        Groupe::factory()->create(['churchId' => $church1->id]);
        Groupe::factory()->create(['churchId' => $church2->id]);

        $pasteur1 = $this->actingPasteur($church1);
        $token = $pasteur1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/groupes');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_pasteur_can_delete_groupe(): void
    {
        $church = Church::factory()->create();
        $groupe = Groupe::factory()->create(['churchId' => $church->id]);

        $pasteur = $this->actingPasteur($church);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/groupes/{$groupe->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('groupes', ['id' => $groupe->id]);
    }

    public function test_deleting_groupe_nulls_membre_reference_instead_of_failing(): void
    {
        $church = Church::factory()->create();
        $groupe = Groupe::factory()->create(['churchId' => $church->id]);

        $membre = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'groupePrincipalId' => $groupe->id,
        ]);
        $membre->groupes()->attach($groupe->id);

        $pasteur = $this->actingPasteur($church);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/groupes/{$groupe->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $membre->id, 'groupePrincipalId' => null]);
        $this->assertDatabaseMissing('groupe_user', ['groupeId' => $groupe->id]);
    }

    // ─── Quartiers ──────────────────────────────────────────────────────────

    public function test_pasteur_can_create_quartier(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->actingPasteur($church);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/quartiers', ['nom' => 'Essos']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('quartiers', ['nom' => 'Essos', 'churchId' => $church->id]);
    }

    // ─── Villes ─────────────────────────────────────────────────────────────

    public function test_pasteur_can_create_ville_with_diaspora_flag(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->actingPasteur($church);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/villes', ['nom' => 'Paris', 'estDiaspora' => true]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('villes', ['nom' => 'Paris', 'estDiaspora' => true, 'churchId' => $church->id]);
    }

    public function test_ville_requires_est_diaspora_flag(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->actingPasteur($church);
        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/villes', ['nom' => 'Douala']);

        $response->assertStatus(422);
    }

    public function test_secretaire_can_create_quartier(): void
    {
        $church = Church::factory()->create();

        $secretaire = User::factory()->create(['role' => User::ROLE_SECRETAIRE, 'churchId' => $church->id]);
        $token = $secretaire->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/quartiers', ['nom' => 'Bastos']);

        $response->assertStatus(201);
    }

    public function test_paroissien_cannot_create_quartier(): void
    {
        $church = Church::factory()->create();

        $paroissien = User::factory()->create(['role' => User::ROLE_PAROISSIEN, 'churchId' => $church->id]);
        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/quartiers', ['nom' => 'Mvog-Ada']);

        $response->assertStatus(403);
    }
}
