<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use App\Models\OTPCode;
use App\Models\MFACredential;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MFAFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_enable_mfa(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/mfa/enable',
            [
                'type' => 'email',
            ]
        );

        $response->assertStatus(401);
    }

    public function test_user_can_enable_mfa(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/auth/mfa/enable',
            [
                'type' => 'email',
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('m_f_a_credentials', [
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'type' => 'mfa',
            'is_used' => false,
        ]);
    }

    public function test_user_can_confirm_mfa_with_valid_otp(): void
    {
        $user = User::factory()->create();

        MFACredential::create([
            'user_id' => $user->id,
            'mfa_enabled' => false,
            'mfa_type' => 'email',
            'backup_codes' => ['ABC123'],
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'type' => 'mfa',
            'code' => '123456',
            'attempts' => 0,
            'is_used' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/auth/mfa/confirm',
            [
                'otp' => '123456',
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('m_f_a_credentials', [
            'user_id' => $user->id,
            'mfa_enabled' => true,
        ]);
    }

    public function test_invalid_otp_cannot_confirm_mfa(): void
    {
        $user = User::factory()->create();

        MFACredential::create([
            'user_id' => $user->id,
            'mfa_enabled' => false,
            'mfa_type' => 'email',
            'backup_codes' => ['ABC123'],
        ]);

        OTPCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'type' => 'mfa',
            'code' => '123456',
            'attempts' => 0,
            'is_used' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/auth/mfa/confirm',
            [
                'otp' => '999999',
            ]
        );

        $response->assertStatus(401);

        $this->assertDatabaseHas('m_f_a_credentials', [
            'user_id' => $user->id,
            'mfa_enabled' => false,
        ]);
    }

    public function test_user_can_view_mfa_status(): void
    {
        $user = User::factory()->create();

        MFACredential::create([
            'user_id' => $user->id,
            'mfa_enabled' => true,
            'mfa_type' => 'email',
            'backup_codes' => ['A', 'B', 'C'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/v1/auth/mfa/status'
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.type', 'email')
            ->assertJsonPath('data.backup_codes_remaining', 3);
    }

    public function test_user_can_disable_mfa(): void
    {
        $user = User::factory()->create();

        MFACredential::create([
            'user_id' => $user->id,
            'mfa_enabled' => true,
            'mfa_type' => 'email',
            'backup_codes' => ['A', 'B'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/auth/mfa/disable'
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('m_f_a_credentials', [
            'user_id' => $user->id,
            'mfa_enabled' => false,
        ]);
    }
}