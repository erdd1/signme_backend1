<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use App\Modules\Auth\Application\Services\GoogleTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function mockGoogleVerifier(string $email): void
    {
        $mock = Mockery::mock(GoogleTokenVerifier::class);
        $mock->shouldReceive('verify')
            ->once()
            ->andReturn([
                'email'          => $email,
                'email_verified' => true,
                'name'           => 'Test User',
                'picture'        => null,
                'sub'            => '1234567890',
            ]);
        $this->app->instance(GoogleTokenVerifier::class, $mock);
    }

    public function test_google_login_requires_id_token(): void
    {
        $response = $this->postJson('/api/v1/auth/google', []);

        $response->assertStatus(422);
    }

    public function test_google_login_fails_without_matching_account(): void
    {
        $this->mockGoogleVerifier('unknown@test.com');

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'fake-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_inactive_user_cannot_login_with_google(): void
    {
        User::factory()->create([
            'email' => 'inactive@test.com',
            'role'  => 'paroissien',
            'actif' => false,
        ]);

        $this->mockGoogleVerifier('inactive@test.com');

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'fake-token',
        ]);

        $response->assertStatus(403);
    }

    public function test_administrator_cannot_login_with_google_on_mobile(): void
    {
        User::factory()->create([
            'email' => 'admin@test.com',
            'role'  => 'administrateur',
            'actif' => true,
        ]);

        $this->mockGoogleVerifier('admin@test.com');

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'fake-token',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_login_with_google_when_account_exists(): void
    {
        User::factory()->create([
            'nom'   => 'Jean Dupont',
            'email' => 'jean@test.com',
            'role'  => 'paroissien',
            'actif' => true,
        ]);

        $this->mockGoogleVerifier('jean@test.com');

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'fake-token',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'id',
                'nom',
                'email',
                'role',
                'actif',
                'access_token',
                'refresh_token',
                'token_type',
                'expires_in',
            ],
        ]);
    }
}
