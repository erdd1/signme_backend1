<?php

namespace Tests\Feature\Celebration;

use App\Models\Celebration;
use App\Models\CelebrationType;
use App\Models\Church;
use App\Models\MFACredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - Module Celebrations (cote admin).
 *
 * Couvre : RBAC admin, edition d'un type (titre/message/active), lecture
 * seule sur la cle/regle de date/genre, liste + suppression d'instances.
 */
class CelebrationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function church(): Church
    {
        return Church::factory()->create();
    }

    private function user(Church $church, string $role): User
    {
        return User::factory()->create(['churchId' => $church->id, 'role' => $role, 'actif' => true]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'administrateur', 'actif' => true, 'is_super_admin' => true]);

        // Le middleware mfa.enabled exige un MFACredential actif pour les admins.
        MFACredential::create([
            'user_id' => $admin->id,
            'mfa_enabled' => true,
            'mfa_type' => 'totp',
        ]);

        return $admin;
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    public function test_guest_cannot_access_admin_celebration_routes(): void
    {
        $this->getJson('/api/admin/celebration-types')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_admin_celebration_routes(): void
    {
        $church = $this->church();
        Sanctum::actingAs($this->user($church, 'pasteur'));
        $this->getJson('/api/admin/celebration-types')->assertStatus(403);
    }

    // =========================================================================
    // TYPES
    // =========================================================================

    public function test_admin_lists_the_nine_types(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/celebration-types')
            ->assertStatus(200)
            ->assertJsonCount(9, 'data');
    }

    public function test_admin_can_update_titre_message_and_active(): void
    {
        $type = CelebrationType::where('key', CelebrationType::KEY_ANNIVERSAIRE)->firstOrFail();
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/admin/celebration-types/{$type->id}", [
            'titre' => 'Joyeux anniversaire à vous !',
            'messageTemplate' => 'Bonjour {nom}, {eglise} vous aime fort.',
            'active' => false,
        ])->assertStatus(200)
          ->assertJsonPath('data.titre', 'Joyeux anniversaire à vous !')
          ->assertJsonPath('data.active', false);

        $this->assertEquals('Bonjour {nom}, {eglise} vous aime fort.', $type->fresh()->messageTemplate);
    }

    public function test_admin_cannot_change_the_structural_date_rule_fields(): void
    {
        $type = CelebrationType::where('key', CelebrationType::KEY_NOEL)->firstOrFail();
        Sanctum::actingAs($this->admin());

        // fixedMonth/fixedDay/key/dateRuleType ne sont pas des champs
        // acceptés par UpdateCelebrationTypeRequest — ignorés silencieusement.
        $this->putJson("/api/admin/celebration-types/{$type->id}", [
            'titre' => 'Nouveau titre',
            'fixedMonth' => 1,
            'fixedDay' => 1,
            'key' => 'autre_cle',
        ])->assertStatus(200);

        $fresh = $type->fresh();
        $this->assertEquals(12, $fresh->fixedMonth);
        $this->assertEquals(25, $fresh->fixedDay);
        $this->assertEquals(CelebrationType::KEY_NOEL, $fresh->key);
    }

    public function test_update_rejects_message_too_short(): void
    {
        $type = CelebrationType::where('key', CelebrationType::KEY_ANNIVERSAIRE)->firstOrFail();
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/admin/celebration-types/{$type->id}", [
            'messageTemplate' => 'court',
        ])->assertStatus(422);
    }

    // =========================================================================
    // INSTANCES
    // =========================================================================

    public function test_admin_lists_all_celebrations_across_churches(): void
    {
        $type = CelebrationType::where('key', CelebrationType::KEY_ANNIVERSAIRE)->firstOrFail();
        $church1 = $this->church();
        $church2 = $this->church();
        $user1 = User::factory()->create(['churchId' => $church1->id]);
        $user2 = User::factory()->create(['churchId' => $church2->id]);

        Celebration::create(['userId' => $user1->id, 'churchId' => $church1->id, 'celebrationTypeId' => $type->id, 'occursOn' => '2026-01-01']);
        Celebration::create(['userId' => $user2->id, 'churchId' => $church2->id, 'celebrationTypeId' => $type->id, 'occursOn' => '2026-01-02']);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/celebrations')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_delete_a_celebration_instance(): void
    {
        $type = CelebrationType::where('key', CelebrationType::KEY_ANNIVERSAIRE)->firstOrFail();
        $church = $this->church();
        $user = User::factory()->create(['churchId' => $church->id]);
        $celebration = Celebration::create([
            'userId' => $user->id, 'churchId' => $church->id,
            'celebrationTypeId' => $type->id, 'occursOn' => '2026-01-01',
        ]);

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/admin/celebrations/{$celebration->uuid}")->assertStatus(200);
        $this->assertDatabaseMissing('celebrations', ['uuid' => $celebration->uuid]);
    }
}
