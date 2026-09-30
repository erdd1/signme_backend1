<?php

namespace Tests\Feature\Tresorerie;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\SortieFinanciere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - Module Sorties Financieres (cote client).
 *
 * Couvre :
 *  - Dashboard stats (global + filtre periode)
 *  - Liste paginee avec filtres type/statut
 *  - Creation sortie simple (approved immediatement)
 *  - Creation sortie de frais (pending jusqu a validation admin)
 *  - Modification d une sortie simple
 *  - Suppression d une sortie simple / sortie frais pending
 *  - Controles RBAC : seuls pasteur et tresorier ont acces
 */
class SortieFinanciereClientTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function church(): Church
    {
        return Church::factory()->create();
    }

    private function pasteur(Church $church): User
    {
        return User::factory()->create([
            'role'     => 'pasteur',
            'churchId' => $church->id,
            'actif'    => true,
        ]);
    }

    private function tresorier(Church $church): User
    {
        return User::factory()->create([
            'role'     => 'tresorier',
            'churchId' => $church->id,
            'actif'    => true,
        ]);
    }

    private function paroissien(Church $church): User
    {
        return User::factory()->create([
            'role'     => 'paroissien',
            'churchId' => $church->id,
            'actif'    => true,
        ]);
    }

    private function sortieSimple(User $user, int $montant = 5000): SortieFinanciere
    {
        return SortieFinanciere::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $user->churchId,
            'userId'      => $user->id,
            'type'        => SortieFinanciere::TYPE_SIMPLE,
            'statut'      => SortieFinanciere::STATUT_APPROVED,
            'montant'     => $montant,
            'description' => 'Achat fournitures',
        ]);
    }

    private function sortieFrais(User $user, int $montant = 3000): SortieFinanciere
    {
        return SortieFinanciere::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $user->churchId,
            'userId'      => $user->id,
            'type'        => SortieFinanciere::TYPE_FRAIS,
            'statut'      => SortieFinanciere::STATUT_PENDING,
            'montant'     => $montant,
            'description' => 'Frais Mobile Money',
        ]);
    }

    private function seedContribution(Church $church, User $auteur, int $montant = 10000): void
    {
        $evenement = Evenement::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Collecte test',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);

        Contribution::create([
            'uuid'            => (string) Str::uuid(),
            'evenementId'     => $evenement->id,
            'userId'          => $auteur->id,
            'montant'         => $montant,
            'frais'           => 100,
            'montantTotal'    => $montant + 100,
            'paymentOperator' => 'ORANGE MONEY',
            'paymentNumber'   => '699000001',
            'statut'          => Contribution::STATUT_COMPLETED,
        ]);
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    public function test_guest_cannot_access_sorties(): void
    {
        $this->getJson('/api/v1/sorties-financieres')->assertStatus(401);
        $this->getJson('/api/v1/sorties-financieres/dashboard')->assertStatus(401);
    }

    public function test_paroissien_cannot_access_sorties(): void
    {
        $church = $this->church();
        Sanctum::actingAs($this->paroissien($church));

        $this->getJson('/api/v1/sorties-financieres')->assertStatus(403);
        $this->getJson('/api/v1/sorties-financieres/dashboard')->assertStatus(403);
    }

    public function test_secretaire_cannot_access_sorties(): void
    {
        $church     = $this->church();
        $secretaire = User::factory()->create(['role' => 'secretaire', 'churchId' => $church->id, 'actif' => true]);
        Sanctum::actingAs($secretaire);

        $this->getJson('/api/v1/sorties-financieres')->assertStatus(403);
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function test_dashboard_retourne_solde_global_correct(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);

        $this->seedContribution($church, $pasteur, 10000); // montantTotal = 10100

        $this->sortieSimple($pasteur, 2000);

        Sanctum::actingAs($pasteur);

        $this->getJson('/api/v1/sorties-financieres/dashboard')
             ->assertStatus(200)
             ->assertJsonPath('data.global.total_encaisse', 10100)
             ->assertJsonPath('data.global.total_sorties_simples', 2000)
             ->assertJsonPath('data.global.solde_actuel', 8100);
    }

    public function test_dashboard_retraits_reduisent_le_solde(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);

        $this->seedContribution($church, $pasteur, 10000); // montantTotal = 10100

        $beneficiaire = \App\Models\RetraitBeneficiaire::create([
            'userId'                  => $pasteur->id,
            'churchId'                => $church->id,
            'channel'                 => \App\Models\RetraitBeneficiaire::CHANNEL_ORANGE,
            'account_number'          => '237699000001',
            'name'                    => $pasteur->nom,
            'notchpay_beneficiary_id' => 'ben_dashboard_test',
            'is_active'               => true,
        ]);

        \App\Models\Retrait::create([
            'churchId' => $church->id,
            'userId'   => $pasteur->id,
            'retrait_beneficiaire_id' => $beneficiaire->id,
            'montant_demande' => 3000,
            'frais' => 0,
            'montant_net' => 3000,
            'statut' => \App\Models\Retrait::STATUT_COMPLETED,
            'reference' => 'RETRAIT-DASHBOARD-1',
        ]);

        // Un retrait `failed` ne doit PAS être déduit.
        \App\Models\Retrait::create([
            'churchId' => $church->id,
            'userId'   => $pasteur->id,
            'retrait_beneficiaire_id' => $beneficiaire->id,
            'montant_demande' => 1000,
            'frais' => 0,
            'montant_net' => 1000,
            'statut' => \App\Models\Retrait::STATUT_FAILED,
            'reference' => 'RETRAIT-DASHBOARD-2',
        ]);

        Sanctum::actingAs($pasteur);

        $this->getJson('/api/v1/sorties-financieres/dashboard')
             ->assertStatus(200)
             ->assertJsonPath('data.global.total_encaisse', 10100)
             ->assertJsonPath('data.global.total_retraits', 3000)
             ->assertJsonPath('data.global.solde_actuel', 7100);
    }

    public function test_dashboard_frais_pending_ne_reduit_pas_solde(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);

        $this->seedContribution($church, $pasteur, 10000); // montantTotal = 10100
        $this->sortieFrais($pasteur, 3000);               // pending : ne deduit pas

        Sanctum::actingAs($pasteur);

        $this->getJson('/api/v1/sorties-financieres/dashboard')
             ->assertStatus(200)
             ->assertJsonPath('data.global.solde_actuel', 10100)
             ->assertJsonPath('data.global.sorties_frais_en_attente', 3000);
    }

    public function test_dashboard_tresorier_acces_sa_propre_eglise(): void
    {
        $church    = $this->church();
        $pasteur   = $this->pasteur($church);
        $tresorier = $this->tresorier($church);
        $this->seedContribution($church, $pasteur, 5000);

        Sanctum::actingAs($tresorier);
        $this->getJson('/api/v1/sorties-financieres/dashboard')->assertStatus(200);
    }

    public function test_dashboard_isolation_inter_eglises(): void
    {
        $church1  = $this->church();
        $church2  = $this->church();
        $pasteur1 = $this->pasteur($church1);
        $pasteur2 = $this->pasteur($church2);

        $this->seedContribution($church1, $pasteur1, 20000);
        $this->sortieSimple($pasteur1, 4000);

        Sanctum::actingAs($pasteur2);

        $this->getJson('/api/v1/sorties-financieres/dashboard')
             ->assertStatus(200)
             ->assertJsonPath('data.global.total_encaisse', 0)
             ->assertJsonPath('data.global.total_sorties_simples', 0)
             ->assertJsonPath('data.global.solde_actuel', 0);
    }

    // =========================================================================
    // LISTE
    // =========================================================================

    public function test_liste_retourne_sorties_de_leglise(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);

        $this->sortieSimple($pasteur, 1000);
        $this->sortieSimple($pasteur, 2000);
        $this->sortieFrais($pasteur, 500);

        Sanctum::actingAs($pasteur);

        $response = $this->getJson('/api/v1/sorties-financieres');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_filtre_type_simple(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->sortieSimple($pasteur, 1000);
        $this->sortieFrais($pasteur, 500);

        Sanctum::actingAs($pasteur);

        $response = $this->getJson('/api/v1/sorties-financieres?type=simple');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('simple', $response->json('data.0.type'));
    }

    public function test_filtre_statut_pending(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->sortieSimple($pasteur);
        $this->sortieFrais($pasteur);

        Sanctum::actingAs($pasteur);

        $response = $this->getJson('/api/v1/sorties-financieres?statut=pending');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('pending', $response->json('data.0.statut'));
    }

    public function test_liste_isolation_inter_eglises(): void
    {
        $church1  = $this->church();
        $church2  = $this->church();
        $pasteur1 = $this->pasteur($church1);
        $pasteur2 = $this->pasteur($church2);

        $this->sortieSimple($pasteur1);
        $this->sortieSimple($pasteur1);

        Sanctum::actingAs($pasteur2);

        $response = $this->getJson('/api/v1/sorties-financieres');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    // =========================================================================
    // CREATION — sortie simple
    // =========================================================================

    public function test_pasteur_cree_sortie_simple_approved(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/sorties-financieres/simple', [
            'montant'     => 5000,
            'description' => 'Achat chaises',
        ])->assertStatus(201)
          ->assertJsonPath('data.type', 'simple')
          ->assertJsonPath('data.statut', 'approved')
          ->assertJsonPath('data.montant', 5000);

        $this->assertDatabaseHas('sorties_financieres', [
            'churchId' => $church->id,
            'type'     => 'simple',
            'statut'   => 'approved',
            'montant'  => 5000,
        ]);
    }

    public function test_creation_simple_sans_montant_est_rejete(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/sorties-financieres/simple', [
            'description' => 'Manque montant',
        ])->assertStatus(422);
    }

    public function test_montant_minimum_100(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/sorties-financieres/simple', [
            'montant'     => 50,
            'description' => 'Trop petit',
        ])->assertStatus(422);
    }

    // =========================================================================
    // CREATION — sortie de frais
    // =========================================================================

    public function test_pasteur_cree_sortie_frais_reste_pending(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);

        // Le service verifie que frais_restant_a_verser >= montant demande.
        // On injecte directement une contribution avec frais=5000 pour avoir assez.
        $evenement = \App\Models\Evenement::create([
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $pasteur->id,
            'titre'       => 'Collecte frais test',
            'description' => str_repeat('a', 25),
            'statut'      => \App\Models\Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);
        \App\Models\Contribution::create([
            'uuid'            => (string) \Illuminate\Support\Str::uuid(),
            'evenementId'     => $evenement->id,
            'userId'          => $pasteur->id,
            'montant'         => 50000,
            'frais'           => 5000,
            'montantTotal'    => 55000,
            'paymentOperator' => 'ORANGE MONEY',
            'paymentNumber'   => '699000099',
            'statut'          => \App\Models\Contribution::STATUT_COMPLETED,
        ]);

        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/sorties-financieres/frais', [
            'montant'     => 2000,
            'description' => 'Frais OM Aout',
        ])->assertStatus(201)
          ->assertJsonPath('data.type', 'frais')
          ->assertJsonPath('data.statut', 'pending');

        $this->assertDatabaseHas('sorties_financieres', [
            'churchId' => $church->id,
            'type'     => 'frais',
            'statut'   => 'pending',
            'montant'  => 2000,
        ]);
    }


    // =========================================================================
    // MODIFICATION
    // =========================================================================

    public function test_pasteur_modifie_sa_sortie_simple(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $sortie  = $this->sortieSimple($pasteur, 3000);
        Sanctum::actingAs($pasteur);

        $this->putJson("/api/v1/sorties-financieres/{$sortie->uuid}", [
            'montant'     => 7000,
            'description' => 'Description modifiee',
        ])->assertStatus(200)
          ->assertJsonPath('data.montant', 7000);

        $this->assertDatabaseHas('sorties_financieres', ['uuid' => $sortie->uuid, 'montant' => 7000]);
    }

    public function test_modification_sortie_frais_pending_interdite(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $sortie  = $this->sortieFrais($pasteur);
        Sanctum::actingAs($pasteur);

        $this->putJson("/api/v1/sorties-financieres/{$sortie->uuid}", [
            'montant'     => 9999,
            'description' => 'Tentative',
        ])->assertStatus(403);
    }

    public function test_modification_sortie_dun_autre_utilisateur_interdite(): void
    {
        $church    = $this->church();
        $pasteur   = $this->pasteur($church);
        $tresorier = $this->tresorier($church);
        $sortie    = $this->sortieSimple($pasteur);
        Sanctum::actingAs($tresorier);

        // Le montant doit etre valide (>=100) pour que la verification d autorisation
        // soit atteinte (le FormRequest valide en premier).
        $this->putJson("/api/v1/sorties-financieres/{$sortie->uuid}", [
            'montant'     => 500,
            'description' => 'Piratage',
        ])->assertStatus(403);
    }

    // =========================================================================
    // SUPPRESSION
    // =========================================================================

    public function test_pasteur_supprime_sa_sortie_simple(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $sortie  = $this->sortieSimple($pasteur);
        Sanctum::actingAs($pasteur);

        $this->deleteJson("/api/v1/sorties-financieres/{$sortie->uuid}")->assertStatus(200);
        $this->assertDatabaseMissing('sorties_financieres', ['uuid' => $sortie->uuid]);
    }

    public function test_pasteur_annule_sortie_frais_pending(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $sortie  = $this->sortieFrais($pasteur);
        Sanctum::actingAs($pasteur);

        $this->deleteJson("/api/v1/sorties-financieres/{$sortie->uuid}")->assertStatus(200);
        $this->assertDatabaseMissing('sorties_financieres', ['uuid' => $sortie->uuid]);
    }

    public function test_suppression_sortie_frais_approuvee_interdite(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);

        $sortie = SortieFinanciere::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $church->id,
            'userId'      => $pasteur->id,
            'type'        => SortieFinanciere::TYPE_FRAIS,
            'statut'      => SortieFinanciere::STATUT_APPROVED,
            'montant'     => 1500,
            'description' => 'Frais valide',
        ]);

        Sanctum::actingAs($pasteur);

        $this->deleteJson("/api/v1/sorties-financieres/{$sortie->uuid}")->assertStatus(403);
    }

    public function test_suppression_sortie_dun_autre_interdite(): void
    {
        $church    = $this->church();
        $pasteur   = $this->pasteur($church);
        $tresorier = $this->tresorier($church);
        $sortie    = $this->sortieSimple($tresorier);
        Sanctum::actingAs($pasteur);

        $this->deleteJson("/api/v1/sorties-financieres/{$sortie->uuid}")->assertStatus(403);
    }
}
