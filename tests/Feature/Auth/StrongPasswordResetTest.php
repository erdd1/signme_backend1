<?php

namespace Tests\Feature\Auth;

use App\Models\OTPCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * StrongPasswordResetTest — Politique de mot de passe forte
 *                            sur l'endpoint POST /api/v1/auth/confirm-password-reset
 *
 * Couvre :
 *   - Mots de passe valides → 200 + mot de passe changé en base
 *   - Mots de passe invalides → 422 avec erreur sur le champ `password`
 *   - Tous les cas limites de la StrongPasswordRule
 */
class StrongPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Prépare un utilisateur et un reset_token valide en cache,
     * puis tente de réinitialiser le mot de passe.
     */
    private function attemptReset(string $password, string $confirmation = ''): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('AncienPass!99'),
        ]);

        Cache::put(
            'password_reset_' . $user->id,
            [
                'token'  => 'valid-reset-token',
                'email'  => $user->email,
                'otp_id' => 1,
            ],
            now()->addMinutes(15)
        );

        return $this->postJson('/api/v1/auth/confirm-password-reset', [
            'email'                 => $user->email,
            'reset_token'           => 'valid-reset-token',
            'password'              => $password,
            'password_confirmation' => $confirmation ?: $password,
        ]);
    }

    // ─── Tests : Mots de passe VALIDES ────────────────────────────────────────

    public function test_valid_password_signme_at_2026_is_accepted(): void
    {
        $this->attemptReset('SignMe@2026')->assertStatus(200);
    }

    public function test_valid_password_moncompte_hash_123_is_accepted(): void
    {
        $this->attemptReset('MonCompte#123')->assertStatus(200);
    }

    public function test_valid_password_securepass_bang_2026_is_accepted(): void
    {
        $this->attemptReset('SecurePass!2026')->assertStatus(200);
    }

    public function test_valid_password_updates_hash_in_database(): void
    {
        $user = User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('AncienPass!99'),
        ]);

        Cache::put(
            'password_reset_' . $user->id,
            ['token' => 'token-abc', 'email' => $user->email, 'otp_id' => 1],
            now()->addMinutes(15)
        );

        $this->postJson('/api/v1/auth/confirm-password-reset', [
            'email'                 => $user->email,
            'reset_token'           => 'token-abc',
            'password'              => 'SignMe@2026',
            'password_confirmation' => 'SignMe@2026',
        ])->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('SignMe@2026', $user->password));
    }

    // ─── Tests : Mots de passe INVALIDES ──────────────────────────────────────

    public function test_rejects_password_too_short(): void
    {
        // 9 caractères — en dessous du minimum de 10
        $this->attemptReset('Pass!12Ab')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_too_long(): void
    {
        // 31 caractères — au dessus du maximum de 30
        $this->attemptReset('SecurePassword!2026AAABBBCCCDDDE')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_uppercase(): void
    {
        $this->attemptReset('password!123')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_lowercase(): void
    {
        $this->attemptReset('PASSWORD!123')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_digit(): void
    {
        $this->attemptReset('Password!abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_special_char(): void
    {
        $this->attemptReset('Password1234')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_with_space(): void
    {
        $this->attemptReset('Pass word!1A')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_common_password_all_lowercase(): void
    {
        $this->attemptReset('password123!')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_with_only_digits(): void
    {
        $this->attemptReset('1234567890')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_any_special_char_example(): void
    {
        // Password123 — manque le caractère spécial
        $this->attemptReset('Password123')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_digit_example(): void
    {
        // Password! — manque le chiffre ET longueur insuffisante
        $this->attemptReset('Password!')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── Tests : Caractères spéciaux autorisés ────────────────────────────────

    public function test_accepts_exclamation_mark_as_special_char(): void
    {
        $this->attemptReset('ValidPass!1')->assertStatus(200);
    }

    public function test_accepts_at_sign_as_special_char(): void
    {
        $this->attemptReset('ValidPass@1')->assertStatus(200);
    }

    public function test_accepts_hash_as_special_char(): void
    {
        $this->attemptReset('ValidPass#1')->assertStatus(200);
    }

    public function test_accepts_dollar_as_special_char(): void
    {
        $this->attemptReset('ValidPass$1')->assertStatus(200);
    }

    public function test_accepts_percent_as_special_char(): void
    {
        $this->attemptReset('ValidPass%1')->assertStatus(200);
    }

    public function test_accepts_caret_as_special_char(): void
    {
        $this->attemptReset('ValidPass^1')->assertStatus(200);
    }

    public function test_accepts_ampersand_as_special_char(): void
    {
        $this->attemptReset('ValidPass&1')->assertStatus(200);
    }

    public function test_accepts_asterisk_as_special_char(): void
    {
        $this->attemptReset('ValidPass*1')->assertStatus(200);
    }

    public function test_accepts_question_mark_as_special_char(): void
    {
        $this->attemptReset('ValidPass?1')->assertStatus(200);
    }

    // ─── Tests : Validation des champs obligatoires ───────────────────────────

    public function test_rejects_missing_password(): void
    {
        $user = User::factory()->create(['email' => 'user@test.com']);

        Cache::put(
            'password_reset_' . $user->id,
            ['token' => 'token-abc', 'email' => $user->email, 'otp_id' => 1],
            now()->addMinutes(15)
        );

        $this->postJson('/api/v1/auth/confirm-password-reset', [
            'email'       => $user->email,
            'reset_token' => 'token-abc',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_mismatch(): void
    {
        $this->attemptReset('SignMe@2026', 'SignMe@2027')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
