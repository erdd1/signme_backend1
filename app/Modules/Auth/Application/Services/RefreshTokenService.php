<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Models\RefreshToken;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * RefreshTokenService
 *
 * Gère la création, rotation et révocation des refresh tokens.
 * Implémente la rotation : chaque refresh crée un nouveau token et invalide l'ancien.
 *
 * @package App\Modules\Auth\Application\Services
 */
class RefreshTokenService
{
    /**
     * Configuration de sécurité
     */
    private array $config;

    public function __construct()
    {
        $this->config = config('security.tokens');
    }

    /**
     * Créer un nouveau refresh token
     *
     * @param User $user
     * @param string|null $deviceInfo Informations appareil (mobile_id, etc)
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return string Token généré
     */
    public function createRefreshToken(
        User $user,
        ?string $deviceInfo = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): string {
        // Générer un token cryptographiquement sûr
        $token = Str::random(80);

        RefreshToken::create([
            'user_id'     => $user->id,
            'token'       => hash('sha256', $token), // Stocker le hash, pas le token brut
            'device_info' => $deviceInfo,
            'ip_address'  => $ipAddress ?? request()->ip(),
            'user_agent'  => $userAgent ?? request()->header('User-Agent'),
            'expires_at'  => now()->addDays($this->config['refresh_token_expires_in_days']),
        ]);

        return $token; // Retourner le token brut au client
    }

    /**
     * Vérifier et utiliser un refresh token
     * Effectue aussi la rotation (ancien token révoqué, nouveau créé)
     *
     * @param string $token
     * @return array|null ['user' => User, 'new_token' => string] ou null si invalide
     * @throws \Exception
     */
    public function refreshAccessToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        // 1. Trouver le token
        $refreshToken = RefreshToken::where('token', $tokenHash)->first();

        if (!$refreshToken || $refreshToken->isExpired()) {
            // Token invalide ou expiré
            return null;
        }

        // 2. Charger l'utilisateur
        $user = $refreshToken->user;
        if (!$user || !$user->actif) {
            return null;
        }

        // 3. Marquer le token actuel comme utilisé
        $refreshToken->markAsUsed();

        // 4. **ROTATION** : Supprimer l'ancien refresh token
        $refreshToken->delete();

        // 5. Créer un nouveau refresh token
        $newRefreshToken = $this->createRefreshToken(
            $user,
            $refreshToken->device_info,
            $refreshToken->ip_address,
            $refreshToken->user_agent
        );

        return [
            'user'      => $user,
            'new_token' => $newRefreshToken,
        ];
    }

    /**
     * Révoquer tous les refresh tokens d'un utilisateur
     * (Utile après : changement password, logout, reset password)
     *
     * @param User $user
     */
    public function revokeAllForUser(User $user): void
    {
        RefreshToken::where('user_id', $user->id)->delete();
    }

    /**
     * Révoquer un refresh token spécifique
     *
     * @param string $token
     */
    public function revokeSingleToken(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        RefreshToken::where('token', $tokenHash)->delete();
    }

    /**
     * Nettoyer les tokens expirés (cron job ou manual cleanup)
     */
    public function cleanupExpiredTokens(): int
    {
        return RefreshToken::expired()->delete();
    }

    /**
     * Compter les refresh tokens valides d'un utilisateur
     */
    public function getActiveTokenCount(User $user): int
    {
        return RefreshToken::forUser($user->id)->count();
    }

    /**
     * Vérifier si l'utilisateur a trop de sessions actives
     * (Indicateur d'activité suspecte)
     *
     * @param User $user
     * @param int $maxSessions Nombre max de sessions simultanées
     * @return bool
     */
    public function hasExcessiveSessions(User $user, int $maxSessions = 5): bool
    {
        return $this->getActiveTokenCount($user) > $maxSessions;
    }
}
