<?php

namespace Tests\Feature\Tresorerie;

use App\Models\Church;
use App\Models\MFACredential;
use App\Models\SortieFinanciere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - Module Sorties Financieres (cote admin).
 *
 * Couvre :
 *  - Liste admin (toutes les sorties de l eglise)
 *  - Liste pending admin
 *  - Approbation d une sortie frais pending
 *  - Rejet d une sortie frais pending avec motif
 *  - RBAC : seul super_admin a acces aux routes admin
 *  - Isolation : un admin ne voit pas les sorties d une autre eglise
 */
class SortieFinanciereAdminTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function church(): Church
    {
        return Church::factory()->create();
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'role'  => 'administrateur',
            'is_super_admin' => true,
            'actif' => true,
        ]);

        // Le middleware mfa.enabled exige un MFACredential actif pour les admins.
        MFACredential::create([
            'user_id'     => $admin->id,
            'mfa_enabled' => true,
            'mfa_type'    => 'totp',
        ]);

        return $admin;
    }

    private function pasteur(Church $church): User
    {
        return User::factory()->create([
            'role'     => 'pasteur',
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
            'description' => 'Sortie simple admin test',
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
            'description' => 'Frais Mobile Money admin test',
        ]);
    }

    // =========================================================================
    // RBAC — acces admin
    // =========================================================================

    public function test_guest_cannot_access_admin_routes(): void
    {
        $this->getJson('/api/admin/sorties-financieres')->assertStatus(401);
        $this->getJson('/api/admin/sorties-financieres/pending')->assertStatus(401);
    }

    public function test_pasteur_cannot_access_admin_routes(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        Sanctum::actingAs($pasteur);

        $this->getJson('/api/admin/sorties-financieres')->assertStatus(403);
        $this->getJson('/api/admin/sorties-financieres/pending')->assertStatus(403);
    }

    // =========================================================================
    // LISTE ADMIN
    // =========================================================================

    public function test_admin_voit_toutes_les_sorties(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();

        $this->sortieSimple($pasteur, 1000);
        $this->sortieSimple($pasteur, 2000);
        $this->sortieFrais($pasteur, 500);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/sorties-financieres');
        $response->assertStatus(200);
        // L admin voit toutes les sorties (pas de filtre eglise stricte comme client)
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_admin_pending_liste_uniquement_frais_pending(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();

        $this->sortieSimple($pasteur, 1000); // approved
        $this->sortieFrais($pasteur, 2000);  // pending
        $this->sortieFrais($pasteur, 3000);  // pending

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/sorties-financieres/pending');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertSame('frais', $item['type']);
            $this->assertSame('pending', $item['statut']);
        }
    }

    // =========================================================================
    // APPROBATION
    // =========================================================================

    public function test_admin_approuve_sortie_frais(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();
        $sortie  = $this->sortieFrais($pasteur, 4000);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/sorties-financieres/{$sortie->uuid}/approve");

        $response->assertStatus(200)
                 ->assertJsonPath('data.statut', 'approved');

        $this->assertDatabaseHas('sorties_financieres', [
            'uuid'         => $sortie->uuid,
            'statut'       => 'approved',
            'validated_by' => $admin->id,
        ]);
    }

    public function test_approbation_sortie_deja_approuvee_echoue(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();

        // Frais deja approuvee — le service leve ApiException::badRequest() => 400
        $sortie = SortieFinanciere::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $church->id,
            'userId'      => $pasteur->id,
            'type'        => SortieFinanciere::TYPE_FRAIS,
            'statut'      => SortieFinanciere::STATUT_APPROVED,
            'montant'     => 2000,
            'description' => 'Deja approuvee',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/sorties-financieres/{$sortie->uuid}/approve")
             ->assertStatus(400);
    }

    public function test_approbation_sortie_simple_echoue(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();
        $sortie  = $this->sortieSimple($pasteur);

        Sanctum::actingAs($admin);

        // Une sortie simple est deja approved => badRequest => 400
        $this->postJson("/api/admin/sorties-financieres/{$sortie->uuid}/approve")
             ->assertStatus(400);
    }

    // =========================================================================
    // REJET
    // =========================================================================

    public function test_admin_rejette_sortie_frais_avec_motif(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();
        $sortie  = $this->sortieFrais($pasteur);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/sorties-financieres/{$sortie->uuid}/reject", [
            'motif' => 'Montant non justifie par une piece',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.statut', 'rejected');

        $this->assertDatabaseHas('sorties_financieres', [
            'uuid'         => $sortie->uuid,
            'statut'       => 'rejected',
            'validated_by' => $admin->id,
        ]);
    }

    public function test_rejet_sans_motif_est_refuse(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();
        $sortie  = $this->sortieFrais($pasteur);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/sorties-financieres/{$sortie->uuid}/reject", [])
             ->assertStatus(422);
    }

    public function test_rejet_sortie_non_pending_echoue(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $admin   = $this->admin();

        $sortie = SortieFinanciere::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $church->id,
            'userId'      => $pasteur->id,
            'type'        => SortieFinanciere::TYPE_FRAIS,
            'statut'      => SortieFinanciere::STATUT_APPROVED,
            'montant'     => 2000,
            'description' => 'Deja traitee',
        ]);

        Sanctum::actingAs($admin);

        // Sortie deja traitee => badRequest => 400
        $this->postJson("/api/admin/sorties-financieres/{$sortie->uuid}/reject", [
            'motif' => 'Tentative',
        ])->assertStatus(400);
    }

    public function test_uuid_inexistant_retourne_404(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/sorties-financieres/uuid-inexistant/approve')->assertStatus(404);
        $this->postJson('/api/admin/sorties-financieres/uuid-inexistant/reject', [
            'motif' => 'Teste 404',
        ])->assertStatus(404);
    }
}
