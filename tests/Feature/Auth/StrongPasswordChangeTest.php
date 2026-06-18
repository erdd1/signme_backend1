<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * StrongPasswordChangeTest — Politique de mot de passe forte
 *                             sur l'endpoint POST /api/v1/auth/change-password
 *
 * Couvre :
 *   - Mots de passe valides → 200 + mot de passe changé en base
 *   - Mots de passe invalides → 422 avec erreur sur le champ `password`
 *   - Mauvais mot de passe actuel → rejet
 *   - Non authentifié → 401
 */
class StrongPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $currentPassword = 'AncienPass!99'): User
    {
        return User::factory()->create([
            'password' => bcrypt($currentPassword),
            'actif'    => true,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function attemptChange(
        User   $user,
        string $newPassword,
        string $confirmation = '',
        string $current = 'AncienPass!99',
    ): \Illuminate\Testing\TestResponse {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->postJson('/api/v1/auth/change-password', [
            'current_password'      => $current,
            'password'              => $newPassword,
            'password_confirmation' => $confirmation ?: $newPassword,
        ]);
    }

    // ─── Tests : Authentification ──────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_change_password(): void
    {
        $this->postJson('/api/v1/auth/change-password', [
            'current_password'      => 'AncienPass!99',
            'password'              => 'SignMe@2026',
            'password_confirmation' => 'SignMe@2026',
        ])->assertStatus(401);
    }

    // ─── Tests : Mots de passe VALIDES ────────────────────────────────────────

    public function test_valid_password_signme_at_2026_is_accepted(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'SignMe@2026')->assertStatus(200);
    }

    public function test_valid_password_moncompte_hash_123_is_accepted(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'MonCompte#123')->assertStatus(200);
    }

    public function test_valid_password_securepass_bang_2026_is_accepted(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'SecurePass!2026')->assertStatus(200);
    }

    public function test_valid_password_is_saved_in_database(): void
    {
        $user = $this->makeUser();

        $this->attemptChange($user, 'SignMe@2026')->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('SignMe@2026', $user->password));
    }

    public function test_old_password_no_longer_valid_after_change(): void
    {
        $user = $this->makeUser('AncienPass!99');

        $this->attemptChange($user, 'SignMe@2026')->assertStatus(200);

        $user->refresh();
        $this->assertFalse(Hash::check('AncienPass!99', $user->password));
    }

    // ─── Tests : Mots de passe INVALIDES ──────────────────────────────────────

    public function test_rejects_password_too_short(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'Pass!12Ab')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_too_long(): void
    {
        $user = $this->makeUser();
        // 31 caractères
        $this->attemptChange($user, 'SecurePassword!2026AAABBBCCCDDDE')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_uppercase(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'password!123')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_lowercase(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'PASSWORD!123')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_digit(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'Password!abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_special_char(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'Password1234')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_with_space(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'Pass word!1A')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_common_password_all_lowercase(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'password123!')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_all_digits_password(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, '1234567890')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_without_special_char_example(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'Password123')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_bang_only_no_digit_example(): void
    {
        // Password! — manque le chiffre ET trop court
        $user = $this->makeUser();
        $this->attemptChange($user, 'Password!')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── Tests : Règles métier spécifiques au change-password ─────────────────

    public function test_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser('AncienPass!99');

        $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->postJson('/api/v1/auth/change-password', [
            'current_password'      => 'MauvaisPass!00',
            'password'              => 'SignMe@2026',
            'password_confirmation' => 'SignMe@2026',
        ])->assertStatus(400);
    }

    public function test_rejects_missing_current_password(): void
    {
        $user = $this->makeUser();

        $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->postJson('/api/v1/auth/change-password', [
            'password'              => 'SignMe@2026',
            'password_confirmation' => 'SignMe@2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_rejects_missing_new_password(): void
    {
        $user = $this->makeUser();

        $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->postJson('/api/v1/auth/change-password', [
            'current_password' => 'AncienPass!99',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_confirmation_mismatch(): void
    {
        $user = $this->makeUser();
        $this->attemptChange($user, 'SignMe@2026', 'SignMe@2027')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── Tests : Caractères spéciaux autorisés ────────────────────────────────

    public function test_accepts_all_allowed_special_chars(): void
    {
        $specialChars = ['!', '@', '#', '$', '%', '^', '&', '*', '?'];

        foreach ($specialChars as $char) {
            $user = $this->makeUser('AncienPass!99');
            $password = 'ValidPass' . $char . '1';

            $this->attemptChange($user, $password)
                ->assertStatus(200, "Caractère spécial '$char' devrait être accepté.");
        }
    }
}
