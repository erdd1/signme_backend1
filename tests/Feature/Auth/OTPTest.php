<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use App\Models\OTPCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OTPTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_password_reset_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
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
            'email' => $user->email,
            'type' => 'password_reset',
            'is_used' => false,
        ]);
    }

    public function test_unknown_email_does_not_leak_information(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/forgot-password',
            [
                'email' => 'unknown@example.com',
            ]
        );

        $response->assertStatus(200);

        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_user_can_verify_valid_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'type' => 'password_reset',
            'code' => '123456',
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
    }

    public function test_invalid_otp_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'type' => 'password_reset',
            'code' => '123456',
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

    public function test_expired_otp_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'type' => 'password_reset',
            'code' => '123456',
            'attempts' => 0,
            'is_used' => false,
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->postJson(
            '/api/v1/auth/verify-password-reset-otp',
            [
                'email' => $user->email,
                'otp' => '123456',
            ]
        );

        $response->assertStatus(400);
    }

    public function test_used_otp_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'type' => 'password_reset',
            'code' => '123456',
            'attempts' => 0,
            'is_used' => true,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson(
            '/api/v1/auth/verify-password-reset-otp',
            [
                'email' => $user->email,
                'otp' => '123456',
            ]
        );

        $response->assertStatus(400);
    }

   public function test_user_can_resend_otp(): void
{
    $user = User::factory()->create([
        'email' => 'john@example.com',
    ]);

    OTPCode::create([
        'user_id' => $user->id,
        'email' => $user->email,
        'type' => 'password_reset',
        'code' => '111111',
        'attempts' => 0,
        'is_used' => false,
        'expires_at' => now()->addMinutes(8),
    ]);

    // Simule que 1 heure s'est écoulée
    $this->travel(1)->hours();

    $response = $this->postJson(
        '/api/v1/auth/resend-password-reset-otp',
        [
            'email' => $user->email,
        ]
    );

    $response->assertStatus(200);
}
    public function test_resend_otp_respects_cooldown(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'type' => 'password_reset',
            'code' => '111111',
            'attempts' => 0,
            'is_used' => false,
            'created_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson(
            '/api/v1/auth/resend-password-reset-otp',
            [
                'email' => $user->email,
            ]
        );

        $response->assertStatus(429);
    }

    public function test_resend_otp_unknown_email_is_rejected(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/resend-password-reset-otp',
            [
                'email' => 'unknown@example.com',
            ]
        );

        $response->assertStatus(400);
    }
}