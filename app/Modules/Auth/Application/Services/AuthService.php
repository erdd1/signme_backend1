<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Modules\User\Http\Resources\UserResource;
use App\Modules\Auth\Domain\AuthEntity;
use App\Core\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Service d'authentification — couche Application
 *
 * Gère toute la logique métier liée à l'authentification :
 * - Connexion / déconnexion avec tokens sécurisés
 * - Réinitialisation de mot de passe
 * - Profil courant
 * - Rate limiting et blocage temporaire
 * - Journalisation sécurité
 *
 * NB : L'inscription classique n'est PAS fournie car les comptes
 * sont créés exclusivement par un admin/pasteur/secrétaire.
 *
 * PHASE 1 SÉCURITÉ :
 * ✅ Access token court (30 min)
 * ✅ Refresh token long (7 jours) avec rotation
 * ✅ Rate limiting + blocage temporaire
 * ✅ Logs sécurité sanitizés
 */
class AuthService
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RateLimitService $rateLimitService,
        private readonly SecurityLogService $securityLogService,
        private readonly MFAService $mfaService,
        private readonly DeviceManagementService $deviceManagementService,
        private readonly SuspiciousActivityDetectionService $suspiciousActivityService,
        private readonly SecurityNotificationService $notificationService,
        private readonly PasswordResetOTPService $passwordResetOTPService,
        private readonly GoogleTokenVerifier $googleTokenVerifier,
    ) {}

    /**
     * Authentifie un utilisateur et retourne access token + refresh token.
     *
     * PHASE 1 : Implémente rotation refresh tokens + rate limiting.
     * PHASE 2 : Intègre MFA si activé, device tracking, détection activités suspectes.
     *
     * @param string $email
     * @param string $password
     * @param string|null $deviceInfo Device ID du mobile (pour tracking)
     * @param string|null $otp Code OTP si MFA activé (PHASE 2)
     * @return AuthEntity
     * @throws ApiException
     */
    public function login(string $email, string $password, ?string $deviceInfo = null, ?string $otp = null): AuthEntity
    {
        $ipAddress = request()->ip();
        $rateKey     = strtolower($email) . '|' . $ipAddress;

        // ─── 1. VÉRIFIER RATE LIMITING ───────────────────────────────────

        $this->rateLimitService->ensureWithinLimit('login', $rateKey);

        if ($this->rateLimitService->isLoginBlocked($email, $ipAddress)) {
            $blockingInfo = $this->rateLimitService->getBlockingInfo($email, $ipAddress);
            $minutes      = $blockingInfo['remaining_minutes'] ?? 1;

            $this->securityLogService->logLoginBlocked($email, $minutes);

            throw ApiException::tooManyRequests(
                'Trop de tentatives échouées. Réessayez dans ' . $minutes . ' minute(s).',
                ['retry_after' => $minutes * 60]
            );
        }

        // ─── 2. VÉRIFIER LES IDENTIFIANTS ────────────────────────────────

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            $blockingResult = $this->rateLimitService->recordFailedLogin(
                $email,
                $ipAddress,
                request()->header('User-Agent')
            );

            $this->rateLimitService->incrementRateLimit('login', $rateKey);
            $this->securityLogService->logFailedLogin($email, 'invalid_credentials');

            if ($blockingResult['is_blocked']) {
                $minutes = $blockingResult['remaining_minutes'] ?? 1;
                throw ApiException::tooManyRequests(
                    'Trop de tentatives échouées. Réessayez dans ' . $minutes . ' minute(s).',
                    ['retry_after' => $minutes * 60]
                );
            }

            throw ApiException::unauthorized('Identifiants invalides.');
        }

        // ─── 3. VÉRIFIER LE COMPTE EST ACTIF ──────────────────────────────

        if (!$user->actif) {
            $this->securityLogService->logFailedLogin($email, 'account_inactive');

            throw ApiException::forbidden(
                'Votre compte a été désactivé. Contactez votre pasteur ou l\'administrateur.'
            );
        }

        // ─── 3b. VÉRIFIER LE RÔLE — administrateurs ne peuvent pas utiliser le client mobile ────────

        if ($user->role === 'administrateur') {
            $this->securityLogService->logFailedLogin(
                $email,
                'unauthorized_role_' . $user->role
            );

            throw ApiException::forbidden(
                'Accès refusé. L\'administrateur doit utiliser l\'interface web. '
                . 'Autres rôles autorisés: paroissien, ancien, pasteur, secrétaire, trésorier.'
            );
        }

        // ─── 4. VÉRIFIER MFA SI ACTIVÉ (PHASE 2) ────────────────────────

        if ($this->mfaService->isMFAEnabled($user)) {
            if (!$otp) {
                $this->mfaService->sendLoginOtpIfEmailType($user);

                throw ApiException::unauthorized(
                    'Authentification à deux facteurs requise. Veuillez fournir le code OTP.',
                    ['mfa_required' => true]
                );
            }

            // Vérifier l'OTP
            if (!$this->mfaService->verifySecondFactor($user, $otp)) {
                $this->securityLogService->log(
                    'mfa_verification_failed',
                    'failed',
                    $user,
                    ['reason' => 'invalid_otp']
                );

                throw ApiException::unauthorized('Code OTP invalide ou expiré.');
            }
        }

        // ─── 5-13. Détection suspecte, device, tokens, logs, notifications ──

        return $this->issueTokensFor($user, $email, $deviceInfo, $ipAddress);
    }

    /**
     * Authentifie un administrateur pour le dashboard web (signme-admin).
     *
     * Miroir de {@see login()} mais réservé au rôle `administrateur` — les
     * autres rôles doivent utiliser le client mobile. Le MFA est obligatoire
     * pour ce rôle : si l'admin ne l'a pas encore configuré, les tokens sont
     * quand même émis (nécessaire pour appeler /admin/auth/mfa/enable) mais
     * `mfaSetupRequired: true` est renvoyé, et le middleware `mfa.enabled`
     * bloque tout le reste de l'API admin tant que le MFA n'est pas confirmé.
     *
     * @throws ApiException
     */
    public function loginAdmin(string $email, string $password, ?string $deviceInfo = null, ?string $otp = null): AuthEntity
    {
        $ipAddress = request()->ip();
        $rateKey     = strtolower($email) . '|' . $ipAddress;

        $this->rateLimitService->ensureWithinLimit('login', $rateKey);

        if ($this->rateLimitService->isLoginBlocked($email, $ipAddress)) {
            $blockingInfo = $this->rateLimitService->getBlockingInfo($email, $ipAddress);
            $minutes      = $blockingInfo['remaining_minutes'] ?? 1;

            $this->securityLogService->logLoginBlocked($email, $minutes);

            throw ApiException::tooManyRequests(
                'Trop de tentatives échouées. Réessayez dans ' . $minutes . ' minute(s).',
                ['retry_after' => $minutes * 60]
            );
        }

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            $blockingResult = $this->rateLimitService->recordFailedLogin(
                $email,
                $ipAddress,
                request()->header('User-Agent')
            );

            $this->rateLimitService->incrementRateLimit('login', $rateKey);
            $this->securityLogService->logFailedLogin($email, 'invalid_credentials');

            if ($blockingResult['is_blocked']) {
                $minutes = $blockingResult['remaining_minutes'] ?? 1;
                throw ApiException::tooManyRequests(
                    'Trop de tentatives échouées. Réessayez dans ' . $minutes . ' minute(s).',
                    ['retry_after' => $minutes * 60]
                );
            }

            throw ApiException::unauthorized('Identifiants invalides.');
        }

        if (!$user->actif) {
            $this->securityLogService->logFailedLogin($email, 'account_inactive');

            throw ApiException::forbidden(
                'Votre compte a été désactivé. Contactez un autre administrateur.'
            );
        }

        if ($user->role !== 'administrateur') {
            $this->securityLogService->logFailedLogin(
                $email,
                'unauthorized_role_' . $user->role
            );

            throw ApiException::forbidden(
                'Accès réservé aux administrateurs. Utilisez l\'application mobile SignMe.'
            );
        }

        if ($this->mfaService->isMFAEnabled($user)) {
            if (!$otp) {
                $this->mfaService->sendLoginOtpIfEmailType($user);

                throw ApiException::unauthorized(
                    'Authentification à deux facteurs requise. Veuillez fournir le code OTP.',
                    ['mfa_required' => true]
                );
            }

            if (!$this->mfaService->verifySecondFactor($user, $otp)) {
                $this->securityLogService->log(
                    'mfa_verification_failed',
                    'failed',
                    $user,
                    ['reason' => 'invalid_otp']
                );

                throw ApiException::unauthorized('Code OTP invalide ou expiré.');
            }
        }

        $mfaSetupRequired = !$this->mfaService->isMFAEnabled($user);

        return $this->issueTokensFor($user, $email, $deviceInfo, $ipAddress, $mfaSetupRequired);
    }

    /**
     * Finalise une authentification réussie (mot de passe ou Google) :
     * détection d'activité suspecte, enregistrement de l'appareil, émission
     * des tokens, logs sécurité et notification de connexion.
     *
     * Partagé par {@see login()} et {@see loginWithGoogle()} pour éviter de
     * dupliquer cette logique de sécurité entre les deux flux.
     */
    private function issueTokensFor(User $user, string $email, ?string $deviceInfo, string $ipAddress, bool $mfaSetupRequired = false): AuthEntity
    {
        // ─── DÉTECTER ACTIVITÉS SUSPECTES (PHASE 2) ─────────────────────

        $this->suspiciousActivityService->analyzeLogin($user, $ipAddress, $deviceInfo);

        // ─── ENREGISTRER L'APPAREIL (PHASE 2) ───────────────────────────

        $this->deviceManagementService->registerDevice($user, $deviceInfo, [
            'ip_address' => $ipAddress,
        ]);

        // ─── CRÉER L'ACCESS TOKEN COURT (30 min) ─────────────────────────

        $accessToken = $user->createToken(
            'eec_signcard_' . $user->role,
            ['*'],
            now()->addMinutes(config('security.tokens.access_token_expires_in_minutes'))
        )->plainTextToken;

        // ─── CRÉER REFRESH TOKEN (avec rotation) ─────────────────────────

        $refreshToken = $this->refreshTokenService->createRefreshToken(
            $user,
            $deviceInfo,
            $ipAddress,
            request()->header('User-Agent')
        );

        // ─── RÉINITIALISER LE COMPTEUR DE RATE LIMITING ────────────────────

        $this->rateLimitService->recordSuccessfulLogin($email, $ipAddress);

        // ─── METTRE À JOUR last_login_at ──────────────────────────────────

        $user->update([
            'last_login_at'         => now(),
            'failed_login_attempts' => 0, // Reset
        ]);

        // ─── LOGGER LA CONNEXION ─────────────────────────────────────────

        $this->securityLogService->logSuccessfulLogin($user, $deviceInfo);

        // ─── ENVOYER NOTIFICATION DE CONNEXION (PHASE 3) ─────────────────

        $this->notificationService->notifyNewLogin(
            $user,
            $ipAddress,
            $deviceInfo,
            null // location (future: from GeoIP)
        );

        // Charger l'église pour retourner le nom aussi
        $user->load('church');

        $churchData = null;
        if ($user->church) {
            $churchData = [
                'id'   => $user->church->id,
                'nom'  => $user->church->nom,
                'ville' => $user->church->ville,
            ];
        }

        // Mini-admin : renvoyer sa grille de permissions au login pour que le
        // dashboard puisse masquer les modules non autorisés dès l'affichage
        // — un super admin n'en a pas besoin (accès total implicite).
        $permissions = null;
        if ($user->isAdmin() && !$user->isSuperAdmin()) {
            $permissions = $user->permissions->map(fn (\App\Models\AdminPermission $p) => [
                'resource'   => $p->resource,
                'can_view'   => $p->can_view,
                'can_create' => $p->can_create,
                'can_update' => $p->can_update,
                'can_delete' => $p->can_delete,
            ])->values()->all();
        }

        return new AuthEntity(
            id:             $user->id,
            nom:            $user->nom,
            email:          $user->email,
            role:           $user->role,
            actif:          $user->actif,
            est_de_service: $user->est_de_service,
            churchId:       $user->churchId,
            photoUrl:       $user->photoUrl,
            token:          $accessToken, // Access token court
            refreshToken:   $refreshToken, // Nouveau : refresh token
            church:         $churchData,    // Nouvelles : données complètes de l'église
            mfaSetupRequired: $mfaSetupRequired,
            isSuperAdmin:   $user->isSuperAdmin(),
            permissions:    $permissions,
        );
    }

    /**
     * Authentifie un utilisateur via Google Sign-In (ID token vérifié auprès
     * de Google) et retourne access token + refresh token, comme login().
     *
     * Aucune création de compte : seuls les comptes déjà créés par un
     * admin/pasteur/secrétaire peuvent se connecter via Google, en faisant
     * correspondre l'email vérifié par Google à un compte existant.
     *
     * @throws ApiException
     */
    public function loginWithGoogle(string $idToken, ?string $deviceInfo = null): AuthEntity
    {
        $ipAddress = request()->ip();

        $googleAccount = $this->googleTokenVerifier->verify($idToken);
        $email = $googleAccount['email'];

        $rateKey = $email . '|' . $ipAddress;
        $this->rateLimitService->ensureWithinLimit('login', $rateKey);

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->securityLogService->logFailedLogin($email, 'google_no_matching_account');
            throw ApiException::unauthorized(
                'Aucun compte SignMe n\'est associé à cette adresse email.'
            );
        }

        if (!$user->actif) {
            $this->securityLogService->logFailedLogin($email, 'account_inactive');
            throw ApiException::forbidden(
                'Votre compte a été désactivé. Contactez votre pasteur ou l\'administrateur.'
            );
        }

        if ($user->role === 'administrateur') {
            $this->securityLogService->logFailedLogin($email, 'unauthorized_role_' . $user->role);
            throw ApiException::forbidden(
                'Accès refusé. L\'administrateur doit utiliser l\'interface web.'
            );
        }

        return $this->issueTokensFor($user, $email, $deviceInfo, $ipAddress);
    }

    /**
     * Déconnecte l'utilisateur en révoquant ALL tokens et refresh tokens.
     *
     * @param User $user
     */
    public function logout(User $user): void
    {
        // ─── Révoquer les access tokens Sanctum ──────────────────────

        $user->tokens()->delete();

        // ─── Révoquer ALL refresh tokens ─────────────────────────────

        $this->refreshTokenService->revokeAllForUser($user);

        // ─── Logger la déconnexion ──────────────────────────────────

        $this->securityLogService->logLogout($user);
    }

    /**
     * Retourne l'entité de l'utilisateur connecté (avec l'église si liée)
     *
     * @param User $user
     * @return array
     */
    public function me(User $user): array
    {
        $user->load(['church', 'quartier', 'ville', 'groupePrincipal', 'groupes']);

        return (new UserResource($user))->resolve();
    }

    /**
     * Rafraîchir l'access token avec un refresh token
     *
     * PHASE 1 : Implémente rotation automatique des refresh tokens.
     *
     * @param string $refreshToken
     * @return array ['access_token' => string, 'refresh_token' => string, 'expires_in' => int]
     * @throws ApiException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $ip = request()->ip();
        $this->rateLimitService->ensureWithinLimit('refresh_token', 'ip:' . $ip);

        // Vérifier le refresh token et obtenir l'utilisateur
        $result = $this->refreshTokenService->refreshAccessToken($refreshToken);

        $this->rateLimitService->incrementRateLimit('refresh_token', 'ip:' . $ip);

        if (!$result) {
            $this->securityLogService->log(
                action: 'token_refresh',
                status: 'failed',
                details: ['reason' => 'invalid_refresh_token']
            );

            throw ApiException::unauthorized('Refresh token invalide ou expiré.');
        }

        $user = $result['user'];
        $newRefreshToken = $result['new_token'];

        // Créer nouveau access token court
        $newAccessToken = $user->createToken(
            'eec_signcard_' . $user->role,
            ['*'],
            now()->addMinutes(config('security.tokens.access_token_expires_in_minutes'))
        )->plainTextToken;

        // Logger le rafraîchissement
        $this->securityLogService->logTokenRefresh($user);

        return [
            'access_token'  => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in'    => config('security.tokens.access_token_expires_in_minutes') * 60, // en secondes
            'token_type'    => 'Bearer',
        ];
    }

    /**
     * Envoie un OTP par email pour réinitialisation mot de passe.
     *
     * @param string $email
     * @throws ApiException
     */
    public function sendPasswordResetOTP(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            // Sécurité : ne pas révéler si email existe
            $this->rateLimitService->incrementRateLimit('password_reset_otp', strtolower($email));
            return;
        }

        $this->passwordResetOTPService->generateAndSendOTP($user, $email);
    }

    /**
     * Vérifie un OTP pour réinitialisation et retourne un token temporaire.
     *
     * @param string $email
     * @param string $otp
     * @return array ['reset_token' => '...']
     * @throws ApiException
     */
    public function verifyPasswordResetOTP(string $email, string $otp): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw ApiException::badRequest('Email introuvable.');
        }

        $resetToken = $this->passwordResetOTPService->verifyOTP($user, $email, $otp);

        return ['reset_token' => $resetToken];
    }

    /**
     * Renvoi un OTP avec gestion des délais.
     *
     * @param string $email
     * @return array
     * @throws ApiException
     */
    public function resendPasswordResetOTP(string $email): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw ApiException::badRequest('Email introuvable.');
        }

        return $this->passwordResetOTPService->resendOTP($user, $email);
    }

    /**
     * Complète la réinitialisation après validation OTP.
     *
     * @param string $email
     * @param string $resetToken
     * @param string $password
     * @throws ApiException
     */
    public function confirmPasswordReset(string $email, string $resetToken, string $password): void
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw ApiException::badRequest('Email introuvable.');
        }

        // Vérifier le reset token en cache
        $cached = cache()->get('password_reset_' . $user->id);
        if (!$cached || $cached['token'] !== $resetToken) {
            throw ApiException::badRequest('Token de réinitialisation invalide ou expiré.');
        }

        // Mettre à jour le mot de passe
        $user->update(['password' => $password]);

        // Révoquer ALL tokens
        $user->tokens()->delete();
        $this->refreshTokenService->revokeAllForUser($user);

        // Logger
        $this->securityLogService->logPasswordReset($user, true);

        // Nettoyer le cache
        cache()->forget('password_reset_' . $user->id);
    }

    

    /**
     * Change le mot de passe d'un utilisateur connecté.
     *
     * PHASE 1 : Révoque tokens + refresh tokens après changement.
     *
     * @param User   $user
     * @param string $currentPassword
     * @param string $newPassword
     * @throws ApiException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            throw ApiException::badRequest('Mot de passe actuel incorrect.');
        }

        $user->update(['password' => $newPassword]);

        // ─── PHASE 1 : Révoquer ALL tokens et forcer re-login ───────

        // Révoquer les access tokens Sanctum
        $user->tokens()->delete();

        // Révoquer les refresh tokens
        $this->refreshTokenService->revokeAllForUser($user);

        // Logger le changement
        $this->securityLogService->logPasswordReset($user, true);
    }
}
