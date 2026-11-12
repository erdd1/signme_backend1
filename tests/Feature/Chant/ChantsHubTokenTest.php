<?php

namespace Tests\Feature\Chant;

use App\Models\Church;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Jeton d'accès au hub Chants (service séparé) : JWT RS256 de courte durée,
 * vérifiable avec la seule clé publique, sans donnée personnelle.
 */
class ChantsHubTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Paire de clés jetable : le test ne dépend d'aucun fichier de la machine.
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $privatePem);
        $this->publicKey = openssl_pkey_get_details($pair)['key'];
        $path = sys_get_temp_dir() . '/chants_jwt_test_' . uniqid() . '.pem';
        file_put_contents($path, $privatePem);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        config([
            'chants.enabled' => true,
            'chants.hub.url' => 'https://chants.test/',
            'chants.hub.jwt_private_key_path' => $path,
        ]);
    }

    private function user(): User
    {
        $church = Church::factory()->create();

        return User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id, 'actif' => true]);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/chants/token')->assertStatus(401);
    }

    public function test_issues_a_short_lived_token_verifiable_with_the_public_key_only(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $data = $this->postJson('/api/v1/chants/token')->assertOk()->json('data');

        $this->assertSame('https://chants.test', $data['hub_url']);           // adresse sans « / » final
        $this->assertSame(900, $data['expires_in']);                          // 15 minutes

        $claims = (array) JWT::decode($data['token'], new Key($this->publicKey, 'RS256'));
        $this->assertSame('signme-api', $claims['iss']);
        $this->assertSame('signme-chants', $claims['aud']);
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame($user->churchId, $claims['church']);
        $this->assertSame(900, $claims['exp'] - $claims['iat']);
    }

    public function test_the_token_carries_no_personal_data(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $token = $this->postJson('/api/v1/chants/token')->json('data.token');
        $payload = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'));

        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString($user->nom, $payload);
        $this->assertSame(['iss', 'aud', 'sub', 'church', 'iat', 'nbf', 'exp'], array_keys(json_decode($payload, true)));
    }

    public function test_an_expired_or_tampered_token_is_rejected_by_the_verifier(): void
    {
        Sanctum::actingAs($this->user());
        $token = $this->postJson('/api/v1/chants/token')->json('data.token');
        $key = new Key($this->publicKey, 'RS256');

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        JWT::decode(substr($token, 0, -4) . 'AAAA', $key);
    }

    public function test_disabled_hub_answers_503_without_a_token(): void
    {
        Sanctum::actingAs($this->user());

        config(['chants.hub.url' => null]);
        $this->postJson('/api/v1/chants/token')->assertStatus(503);

        config(['chants.hub.url' => 'https://chants.test', 'chants.enabled' => false]);
        $this->postJson('/api/v1/chants/token')->assertStatus(503);
    }

    public function test_a_missing_private_key_answers_503(): void
    {
        Sanctum::actingAs($this->user());
        config(['chants.hub.jwt_private_key_path' => '/inexistant/cle.pem']);

        $this->postJson('/api/v1/chants/token')->assertStatus(503);
    }
}
