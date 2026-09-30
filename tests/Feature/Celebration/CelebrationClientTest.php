<?php

namespace Tests\Feature\Celebration;

use App\Models\Celebration;
use App\Models\CelebrationType;
use App\Models\Church;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - Module Celebrations (cote client).
 *
 * Couvre : accès tous rôles, liste/detail/lecture, isolation inter-utilisateur,
 * rendu du message ({nom}/{eglise}).
 */
class CelebrationClientTest extends TestCase
{
    use RefreshDatabase;

    private function church(array $overrides = []): Church
    {
        return Church::factory()->create($overrides);
    }

    private function user(Church $church, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'churchId' => $church->id,
            'actif' => true,
        ], $overrides));
    }

    private function birthdayType(): CelebrationType
    {
        return CelebrationType::where('key', CelebrationType::KEY_ANNIVERSAIRE)->firstOrFail();
    }

    private function celebration(User $user, CelebrationType $type, string $occursOn = '2026-07-02'): Celebration
    {
        return Celebration::create([
            'userId' => $user->id,
            'churchId' => $user->churchId,
            'celebrationTypeId' => $type->id,
            'occursOn' => $occursOn,
        ]);
    }

    // =========================================================================
    // SEED — les 9 types doivent exister après migration
    // =========================================================================

    public function test_the_nine_celebration_types_are_seeded(): void
    {
        $this->assertEquals(9, CelebrationType::count());
        $this->assertNotNull(CelebrationType::where('key', CelebrationType::KEY_JOURNEE_FEMME)->first());
    }

    // =========================================================================
    // RBAC — tous les rôles authentifiés ont accès
    // =========================================================================

    public function test_guest_cannot_access_celebrations(): void
    {
        $this->getJson('/api/v1/celebrations')->assertStatus(401);
    }

    public function test_all_roles_can_list_their_celebrations(): void
    {
        $church = $this->church();
        foreach (['paroissien', 'secretaire', 'pasteur', 'tresorier'] as $role) {
            $user = $this->user($church, ['role' => $role]);
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/celebrations')->assertStatus(200);
        }
    }

    // =========================================================================
    // LISTE / DETAIL
    // =========================================================================

    public function test_list_returns_only_my_celebrations(): void
    {
        $church = $this->church(['nom' => 'EEC MESSAMEDONGO']);
        $me = $this->user($church, ['nom' => 'Nouetagni Yvan']);
        $other = $this->user($church, ['nom' => 'Quelqu\'un d\'autre']);
        $type = $this->birthdayType();

        $this->celebration($me, $type);
        $this->celebration($other, $type, '2026-08-01');

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/celebrations')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_message_is_rendered_with_user_name_and_church_name(): void
    {
        $church = $this->church(['nom' => 'EEC MESSAMEDONGO']);
        $user = $this->user($church, ['nom' => 'Nouetagni Yvan']);
        $celebration = $this->celebration($user, $this->birthdayType());

        Sanctum::actingAs($user);
        $this->getJson("/api/v1/celebrations/{$celebration->uuid}")
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'anniversaire')
            ->assertJsonFragment(['isRead' => false]);

        $body = json_decode(
            $this->getJson("/api/v1/celebrations/{$celebration->uuid}")->getContent(),
            true
        );
        $this->assertStringContainsString('Nouetagni Yvan', $body['data']['message']);
        $this->assertStringContainsString('EEC MESSAMEDONGO', $body['data']['message']);
    }

    public function test_show_another_users_celebration_returns_404(): void
    {
        $church = $this->church();
        $owner = $this->user($church);
        $intruder = $this->user($church);
        $celebration = $this->celebration($owner, $this->birthdayType());

        Sanctum::actingAs($intruder);
        $this->getJson("/api/v1/celebrations/{$celebration->uuid}")->assertStatus(404);
    }

    // =========================================================================
    // MARQUER COMME LUE
    // =========================================================================

    public function test_mark_read_updates_status(): void
    {
        $church = $this->church();
        $user = $this->user($church);
        $celebration = $this->celebration($user, $this->birthdayType());

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/celebrations/{$celebration->uuid}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.isRead', true);

        $this->assertNotNull($celebration->fresh()->readAt);
    }

    public function test_cannot_mark_another_users_celebration_as_read(): void
    {
        $church = $this->church();
        $owner = $this->user($church);
        $intruder = $this->user($church);
        $celebration = $this->celebration($owner, $this->birthdayType());

        Sanctum::actingAs($intruder);
        $this->postJson("/api/v1/celebrations/{$celebration->uuid}/read")->assertStatus(404);
        $this->assertFalse($celebration->fresh()->isRead);
    }
}
