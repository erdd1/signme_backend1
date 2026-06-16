<?php

namespace Tests\Feature\Auth;

use App\Models\OTPCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_password_reset_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'john@test.com',
        ]);

        $response = $this->postJson(
            '/api/v1/auth/forgot-password',
            [
                'email' => $user->email,
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'type' => 'password_reset',
            'email' => $user->email,
        ]);
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/forgot-password',
            [
                'email' => 'unknown@test.com',
            ]
        );

        $response->assertStatus(200);
    }

    public function test_user_can_verify_password_reset_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'john@test.com',
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'code' => '123456',
            'type' => 'password_reset',
            'email' => $user->email,
            'attempts' => 0,
            'is_used' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson(
            '/api/v1/auth/verify-password-reset-otp',
            [
                'email' => $user->email,
                'otp' => '123456',
            ]
        );

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'reset_token',
            ],
        ]);

        $this->assertNotNull(
            Cache::get('password_reset_' . $user->id)
        );
    }

    public function test_invalid_otp_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'john@test.com',
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'code' => '123456',
            'type' => 'password_reset',
            'email' => $user->email,
            'attempts' => 0,
            'is_used' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson(
            '/api/v1/auth/verify-password-reset-otp',
            [
                'email' => $user->email,
                'otp' => '999999',
            ]
        );

        $response->assertStatus(400);
    }

    public function test_user_can_confirm_password_reset(): void
    {
        $user = User::factory()->create([
            'email' => 'john@test.com',
            'password' => bcrypt('ancien-password'),
        ]);

        Cache::put(
            'password_reset_' . $user->id,
            [
                'token' => 'reset-token-123',
                'email' => $user->email,
                'otp_id' => 1,
            ],
            now()->addMinutes(15)
        );

        $response = $this->postJson(
            '/api/v1/auth/confirm-password-reset',
            [
                'email' => $user->email,
                'reset_token' => 'reset-token-123',
                'password' => 'NouveauPassword123!',
                'password_confirmation' => 'NouveauPassword123!',
            ]
        );

        $response->assertStatus(200);

        $user->refresh();

        $this->assertTrue(
            Hash::check(
                'NouveauPassword123!',
                $user->password
            )
        );
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'john@test.com',
        ]);

        Cache::put(
            'password_reset_' . $user->id,
            [
                'token' => 'real-token',
                'email' => $user->email,
                'otp_id' => 1,
            ],
            now()->addMinutes(15)
        );

        $response = $this->postJson(
            '/api/v1/auth/confirm-password-reset',
            [
                'email' => $user->email,
                'reset_token' => 'fake-token',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]
        );

        $response->assertStatus(400);
    }
}