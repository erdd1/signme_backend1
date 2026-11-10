<?php

namespace Tests\Feature\Assistant;

use App\Models\Church;
use App\Models\MFACredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - Module Assistant religieux.
 *
 * Aucun appel réseau réel : le moteur est simulé via Http::fake().
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/assistant/chat';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'assistant.api_key' => 'cle-test',
            'assistant.base_url' => 'https://moteur.test/v1',
        ]);
    }

    private function engineReplies(string $text = 'Le pardon est un don de Dieu (Éphésiens 4:32).'): void
    {
        Http::fake(['moteur.test/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
        ])]);
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

    private function question(string $text = 'Que dit la Bible sur le pardon ?'): array
    {
        return ['messages' => [['role' => 'user', 'content' => $text]]];
    }

    // ─── Accès ───────────────────────────────────────────────────────────────

    public function test_guest_is_rejected(): void
    {
        $this->postJson(self::URL, $this->question())->assertStatus(401);
    }

    public function test_admin_cannot_use_the_client_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'administrateur', 'actif' => true, 'is_super_admin' => true]);
        MFACredential::create(['user_id' => $admin->id, 'mfa_enabled' => true, 'mfa_type' => 'totp']);
        Sanctum::actingAs($admin);

        $this->postJson(self::URL, $this->question())->assertStatus(403);
    }

    /** @dataProvider clientRoles */
    public function test_every_client_role_can_chat(string $role): void
    {
        $this->engineReplies();
        $this->login($role);

        $this->postJson(self::URL, $this->question())
            ->assertOk()
            ->assertJsonPath('data.reply', 'Le pardon est un don de Dieu (Éphésiens 4:32).');
    }

    public static function clientRoles(): array
    {
        return [['paroissien'], ['ancien'], ['secretaire'], ['tresorier'], ['pasteur']];
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_validation_rejects_bad_payloads(): void
    {
        Http::fake();
        $this->login();

        $this->postJson(self::URL, [])->assertStatus(422);
        $this->postJson(self::URL, ['messages' => []])->assertStatus(422);
        $this->postJson(self::URL, ['messages' => [['role' => 'system', 'content' => 'x']]])->assertStatus(422);
        $this->postJson(self::URL, ['messages' => [['role' => 'user', 'content' => '']]])->assertStatus(422);
        $this->postJson(self::URL, $this->question(str_repeat('a', 2001)))->assertStatus(422);
        $this->postJson(self::URL, ['messages' => [
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => 'Paix à vous'],
        ]])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ─── Appel au moteur ─────────────────────────────────────────────────────

    public function test_engine_receives_system_prompt_then_conversation(): void
    {
        $this->engineReplies();
        $this->login();

        $this->postJson(self::URL, ['messages' => [
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => 'Paix à vous'],
            ['role' => 'user', 'content' => 'Parle-moi du pardon'],
        ]])->assertOk();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://moteur.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer cle-test')
                && $body['model'] === config('assistant.model')
                && $body['messages'][0]['role'] === 'system'
                && str_contains($body['messages'][0]['content'], 'Louis Segond')
                && array_column(array_slice($body['messages'], 1), 'content')
                    === ['Bonjour', 'Paix à vous', 'Parle-moi du pardon'];
        });
    }

    public function test_only_the_most_recent_messages_are_sent(): void
    {
        config(['assistant.max_messages' => 3]);
        $this->engineReplies();
        $this->login();

        $messages = [];
        foreach (range(1, 5) as $i) {
            $messages[] = ['role' => 'user', 'content' => "q{$i}"];
            $messages[] = ['role' => 'assistant', 'content' => "r{$i}"];
        }
        $messages[] = ['role' => 'user', 'content' => 'derniere'];

        $this->postJson(self::URL, ['messages' => $messages])->assertOk();

        Http::assertSent(fn (Request $r) => array_column(array_slice($r->data()['messages'], 1), 'content')
            === ['q5', 'r5', 'derniere']);
    }

    // ─── Résilience ──────────────────────────────────────────────────────────

    public function test_engine_error_returns_503_and_does_not_consume_quota(): void
    {
        config(['assistant.daily_limit_per_user' => 1]);
        Http::fake(['moteur.test/*' => Http::sequence()
            ->push([], 500)
            ->push(['choices' => [['message' => ['content' => 'Amen']]]])]);
        $this->login();

        $this->postJson(self::URL, $this->question())->assertStatus(503)->assertJsonPath('success', false);
        $this->postJson(self::URL, $this->question())->assertOk();
    }

    public function test_engine_rate_limit_is_reported_as_busy(): void
    {
        Http::fake(['moteur.test/*' => Http::response([], 429)]);
        $this->login();

        $this->postJson(self::URL, $this->question())
            ->assertStatus(503)
            ->assertJsonPath('message', "L'assistant est très sollicité en ce moment. Réessayez dans quelques minutes.");
    }

    public function test_empty_engine_reply_is_a_failure(): void
    {
        $this->engineReplies('   ');
        $this->login();

        $this->postJson(self::URL, $this->question())->assertStatus(503);
    }

    public function test_missing_api_key_is_a_503_without_calling_the_engine(): void
    {
        config(['assistant.api_key' => null]);
        Http::fake();
        $this->login();

        $this->postJson(self::URL, $this->question())->assertStatus(503);
        Http::assertNothingSent();
    }

    // ─── Garde-fous ──────────────────────────────────────────────────────────

    public function test_kill_switch_disables_the_module(): void
    {
        config(['assistant.enabled' => false]);
        Http::fake();
        $this->login();

        $this->postJson(self::URL, $this->question())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_daily_limit_per_user_is_enforced_and_independent_between_users(): void
    {
        config(['assistant.daily_limit_per_user' => 2]);
        $this->engineReplies();

        $this->login();
        $this->postJson(self::URL, $this->question())->assertOk();
        $this->postJson(self::URL, $this->question())->assertOk();
        $this->postJson(self::URL, $this->question())->assertStatus(429);

        $this->login('pasteur');
        $this->postJson(self::URL, $this->question())->assertOk();
    }

    public function test_global_daily_limit_protects_the_engine_quota(): void
    {
        config(['assistant.daily_limit_global' => 1]);
        $this->engineReplies();

        $this->login();
        $this->postJson(self::URL, $this->question())->assertOk();

        $this->login('ancien');
        $this->postJson(self::URL, $this->question())->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_per_minute_rate_limit_is_enforced(): void
    {
        config(['assistant.rate_limit_per_minute' => 2]);
        $this->engineReplies();
        $this->login();

        $this->postJson(self::URL, $this->question())->assertOk();
        $this->postJson(self::URL, $this->question())->assertOk();
        $this->postJson(self::URL, $this->question())
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Vous posez vos questions un peu vite. Patientez quelques instants.');
    }

    public function test_message_content_is_never_logged(): void
    {
        $this->engineReplies('Une réponse confidentielle');
        Log::spy();
        $this->login();

        $this->postJson(self::URL, $this->question('Une question très personnelle'))->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) =>
            !str_contains(json_encode($context), 'personnelle') && !str_contains(json_encode($context), 'confidentielle')
        )->once();
    }
}
