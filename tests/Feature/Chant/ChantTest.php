<?php

namespace Tests\Feature\Chant;

use App\Models\Church;
use App\Models\MFACredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - Module Chants.
 *
 * Aucun appel réseau réel : LRCLIB et Genius sont simulés via Http::fake().
 */
class ChantTest extends TestCase
{
    use RefreshDatabase;

    private const LYRICS = "Toi le début et la fin\nDieu sans maître et sans égal\n\nGrâce infinie\nDe notre Dieu\n\nToi le début et la fin\nDieu sans maître et sans égal";

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['chants.genius.token' => 'token-test']);
    }

    private function track(int $id, string $title = 'Grâce Infinie', string $artist = 'Collectif Cieux Ouverts', array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'name' => $title, 'trackName' => $title, 'artistName' => $artist,
            'albumName' => 'Album', 'duration' => 315.4, 'instrumental' => false,
            'plainLyrics' => self::LYRICS, 'syncedLyrics' => null,
        ], $extra);
    }

    private function login(string $role = 'paroissien'): User
    {
        $user = User::factory()->create([
            'churchId' => Church::factory()->create()->id,
            'role' => $role,
            'actif' => true,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    // ─── Accès ───────────────────────────────────────────────────────────────

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/chants/search?q=grace')->assertStatus(401);
    }

    public function test_admin_cannot_use_the_client_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'administrateur', 'actif' => true, 'is_super_admin' => true]);
        MFACredential::create(['user_id' => $admin->id, 'mfa_enabled' => true, 'mfa_type' => 'totp']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/chants/search?q=grace')->assertStatus(403);
    }

    /** @dataProvider clientRoles */
    public function test_every_client_role_can_search(string $role): void
    {
        Http::fake(['lrclib.net/*' => Http::response([$this->track(1)])]);
        $this->login($role);

        $this->getJson('/api/v1/chants/search?q=grace')->assertOk()->assertJsonPath('success', true);
    }

    public static function clientRoles(): array
    {
        return [['paroissien'], ['ancien'], ['secretaire'], ['tresorier'], ['pasteur']];
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_query_is_required_and_bounded(): void
    {
        $this->login();

        $this->getJson('/api/v1/chants/search')->assertStatus(422);
        $this->getJson('/api/v1/chants/search?q=a')->assertStatus(422);
        $this->getJson('/api/v1/chants/search?q=' . str_repeat('a', 121))->assertStatus(422);
    }

    // ─── Recherche par titre ─────────────────────────────────────────────────

    public function test_search_groups_versions_of_the_same_title(): void
    {
        Http::fake(['lrclib.net/api/search*' => Http::response([
            $this->track(10),
            $this->track(11, 'Grâce infinie', 'Autre groupe'),
            $this->track(12, "J'élève", 'Collectif Cieux Ouverts'),
        ])]);
        $this->login();

        $response = $this->getJson('/api/v1/chants/search?q=grace infinie')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', 10);
        $response->assertJsonCount(2, 'data.0.versions');
        $response->assertJsonPath('data.0.excerpt.0', 'Toi le début et la fin');
        $this->assertArrayNotHasKey('lyrics', $response->json('data.0'));
    }

    public function test_instrumentals_and_empty_lyrics_are_dropped(): void
    {
        Http::fake(['lrclib.net/api/search*' => Http::response([
            $this->track(1, extra: ['instrumental' => true]),
            $this->track(2, extra: ['plainLyrics' => '   ']),
            $this->track(3),
        ])]);
        $this->login();

        $this->getJson('/api/v1/chants/search?q=grace')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_results_are_cached(): void
    {
        Http::fake(['lrclib.net/api/search*' => Http::response([$this->track(1)])]);
        $this->login();

        $this->getJson('/api/v1/chants/search?q=Grace')->assertOk();
        $this->getJson('/api/v1/chants/search?q=%20%20grace%20')->assertOk();

        Http::assertSentCount(1);
    }

    // ─── Recherche par extrait de paroles ────────────────────────────────────

    public function test_lyrics_fragment_falls_back_to_genius_then_lrclib(): void
    {
        Http::fake([
            'lrclib.net/api/search?q=Toi*' => Http::response([]),
            'api.genius.com/search*' => Http::response(['response' => ['hits' => [
                ['type' => 'song', 'result' => ['title' => 'Grâce Infinie', 'primary_artist' => ['name' => 'Collectif Cieux Ouverts']]],
            ]]]),
            'lrclib.net/api/search?q=Gr*' => Http::response([$this->track(10)]),
        ]);
        $this->login();

        $this->getJson('/api/v1/chants/search?q=Toi le début et la fin')
            ->assertOk()
            ->assertJsonPath('data.0.id', 10);
    }

    public function test_without_genius_token_fragment_search_returns_empty_list(): void
    {
        config(['chants.genius.token' => null]);
        Http::fake(['lrclib.net/*' => Http::response([])]);
        $this->login();

        $this->getJson('/api/v1/chants/search?q=Toi le début')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_genius_failure_does_not_break_the_search(): void
    {
        Http::fake([
            'lrclib.net/*' => Http::response([]),
            'api.genius.com/*' => Http::response([], 500),
        ]);
        $this->login();

        $this->getJson('/api/v1/chants/search?q=Toi le début')->assertOk()->assertJsonCount(0, 'data');
    }

    // ─── Détail ──────────────────────────────────────────────────────────────

    public function test_show_returns_sections_versions_and_source_from_search_cache(): void
    {
        Http::fake(['lrclib.net/api/search*' => Http::response([$this->track(10), $this->track(11, 'Grâce Infinie', 'Autre')])]);
        $this->login();
        $this->getJson('/api/v1/chants/search?q=grace')->assertOk();

        $response = $this->getJson('/api/v1/chants/10')->assertOk();

        $response->assertJsonPath('data.title', 'Grâce Infinie');
        $response->assertJsonPath('data.sections.0.type', 'refrain');
        $response->assertJsonCount(2, 'data.versions');
        $response->assertJsonPath('data.source.nom', 'LRCLIB');
        Http::assertSentCount(1);
    }

    public function test_show_fetches_from_lrclib_when_not_cached(): void
    {
        Http::fake(['lrclib.net/api/get/77' => Http::response($this->track(77))]);
        $this->login();

        $this->getJson('/api/v1/chants/77')->assertOk()->assertJsonPath('data.id', 77);
    }

    public function test_show_unknown_chant_is_404(): void
    {
        Http::fake(['lrclib.net/api/get/*' => Http::response(['message' => 'not found'], 404)]);
        $this->login();

        $this->getJson('/api/v1/chants/999')->assertStatus(404);
    }

    public function test_non_numeric_id_is_not_routed(): void
    {
        $this->login();

        $this->getJson('/api/v1/chants/abc')->assertStatus(404);
    }

    // ─── Résilience & garde-fous ─────────────────────────────────────────────

    public function test_lrclib_failure_returns_503_with_friendly_message(): void
    {
        Http::fake(['lrclib.net/*' => Http::response([], 500)]);
        $this->login();

        $this->getJson('/api/v1/chants/search?q=grace')
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_kill_switch_disables_the_module(): void
    {
        config(['chants.enabled' => false]);
        Http::fake();
        $this->login();

        $this->getJson('/api/v1/chants/search?q=grace')->assertStatus(503);
        $this->getJson('/api/v1/chants/1')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_rate_limit_is_enforced_per_user(): void
    {
        config(['chants.rate_limit_per_minute' => 2]);
        Http::fake(['lrclib.net/*' => Http::response([$this->track(1)])]);
        $this->login();

        $this->getJson('/api/v1/chants/search?q=aa')->assertOk();
        $this->getJson('/api/v1/chants/search?q=bb')->assertOk();
        $this->getJson('/api/v1/chants/search?q=cc')->assertStatus(429);
    }
}
