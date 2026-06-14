<?php

namespace App\Modules\Auth\Http;

use App\Http\Controllers\Controller;
use App\Core\Traits\ApiResponseTrait;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Modules\Auth\Application\Services\AuthService;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RefreshTokenRequest;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\EnableMFARequest;
use App\Modules\Auth\Http\Requests\ConfirmMFARequest;
use App\Modules\Auth\Http\Requests\VerifyPasswordResetOTPRequest;
use App\Modules\Auth\Http\Requests\ResendOTPRequest;
use App\Modules\Auth\Http\Requests\ConfirmPasswordResetRequest;

/**
 * AuthController — Module Auth
 *
 * Gère tous les endpoints d'authentification :
 * POST  /api/v1/auth/login           → Connexion
 * POST  /api/v1/auth/refresh         → Rafraîchir le token [PHASE 1]
 * POST  /api/v1/auth/logout          → Déconnexion [auth]
 * GET   /api/v1/auth/me              → Profil courant [auth]
 * POST  /api/v1/auth/forgot-password → Email de reset
 * POST  /api/v1/auth/reset-password  → Réinitialiser mot de passe
 * POST  /api/v1/auth/change-password → Changer mot de passe [auth]
 *
 * PHASE 1 : Rotation des refresh tokens + rate limiting
 */
class AuthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly AuthService $authService,
        private readonly \App\Modules\Auth\Application\Services\MFAService $mfaService,
        private readonly \App\Modules\Auth\Application\Services\DeviceManagementService $deviceService,
        private readonly \App\Modules\Auth\Application\Services\EmailVerificationService $emailVerificationService,
        private readonly \App\Modules\Auth\Application\Services\SuspiciousActivityDetectionService $suspiciousActivityService,
        private readonly \App\Modules\Auth\Application\Services\SecurityNotificationService $notificationService,
        private readonly \App\Modules\Auth\Application\Services\AuditLogService $auditLogService,
    ) {}

    /**
     * POST /api/v1/auth/login
     *
     * Authentifie un membre et retourne access token + refresh token.
     * PHASE 1 : Access token court (30 min) + refresh token long (7 jours)
     * PHASE 2 : Support MFA avec OTP
     *
     * Body: { email, password, device_id?, otp? }
     * Réponse: { success, message, data: { id, nom, email, role, access_token, refresh_token, expires_in, token_type } }
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $entity = $this->authService->login(
            $request->email,
            $request->password,
            $request->device_id,
            $request->otp // PHASE 2 : MFA OTP support
        );

        return $this->successResponse(
            $entity->toArray(),
            'Connexion réussie. Bienvenue ' . $entity->nom . ' !'
        );
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Rafraîchit l'access token avec le refresh token (rotation).
     * PHASE 1 : Implémente rotation automatique des refresh tokens.
     *
     * Body: { refresh_token }
     * Réponse: { success, message, data: { access_token, refresh_token, expires_in, token_type } }
     */
    public function refreshToken(RefreshTokenRequest $request): JsonResponse
    {
        $tokens = $this->authService->refreshAccessToken($request->refresh_token);

        return $this->successResponse(
            $tokens,
            'Token rafraîchi avec succès.'
        );
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Révoque le token courant ET tous les refresh tokens de l'utilisateur.
     * PHASE 1 : Révoque les refresh tokens
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'Déconnexion réussie.');
    }

    /**
     * GET /api/v1/auth/me
     *
     * Retourne le profil complet de l'utilisateur connecté avec son église.
     */
    public function me(Request $request): JsonResponse
    {
        $data = $this->authService->me($request->user());

        return $this->successResponse($data, 'Profil récupéré avec succès.');
    }

    /**
     * POST /api/v1/auth/forgot-password
     *
     * Envoie un OTP à 6 chiffres par email.
     * Body: { email }
     *
     * NB : Réponse identique que l'email existe ou non (sécurité).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->sendPasswordResetOTP($request->email);

        return $this->successResponse(
            null,
            'Si cet email existe, un code OTP a été envoyé.'
        );
    }

    /**
     * POST /api/v1/auth/verify-password-reset-otp
     *
     * Vérifie l'OTP et retourne un token temporaire de reset
     * Body: { email, otp }
     *
     * Réponse: { success, message, data: { reset_token } }
     */
    public function verifyPasswordResetOTP(VerifyPasswordResetOTPRequest $request): JsonResponse
    {
        $data = $this->authService->verifyPasswordResetOTP(
            $request->email,
            $request->otp
        );

        return $this->successResponse(
            $data,
            'OTP vérifié avec succès.'
        );
    }

    /**
     * POST /api/v1/auth/resend-password-reset-otp
     *
     * Renvoie un OTP avec gestion escalade délais
     * Body: { email }
     *
     * Réponse: { success, message, data: { retry_after } }
     */
    public function resendPasswordResetOTP(ResendOTPRequest $request): JsonResponse
    {
        $data = $this->authService->resendPasswordResetOTP($request->email);

        return $this->successResponse(
            $data,
            $data['message']
        );
    }

    /**
     * POST /api/v1/auth/confirm-password-reset
     *
     * Complète la réinitialisation après validation OTP
     * Body: { reset_token, email, password, password_confirmation }
     */
    public function confirmPasswordReset(ConfirmPasswordResetRequest $request): JsonResponse
    {
        $this->authService->confirmPasswordReset(
            $request->email,
            $request->reset_token,
            $request->password
        );

        return $this->successResponse(
            null,
            'Mot de passe réinitialisé avec succès. Veuillez vous reconnecter.'
        );
    }

    /**
     * POST /api/v1/auth/reset-password
     *
     * DEPRECATED: Réinitialise le mot de passe avec le token reçu par email.
     * Conservé pour compatibilité rétroactive.
     *
     * Body: { reset_token, email, password, password_confirmation }
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword(
            $request->reset_token,
            $request->email,
            $request->password
        );

        return $this->successResponse(
            null,
            'Mot de passe réinitialisé avec succès. Veuillez vous reconnecter.'
        );
    }

    /**
     * POST /api/v1/auth/change-password
     *
     * Change le mot de passe d'un utilisateur connecté.
     * PHASE 1 : Révoque les refresh tokens après changement (forcer re-login)
     *
     * Body: { current_password, password, password_confirmation }
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword(
            $request->user(),
            $request->current_password,
            $request->password
        );

        return $this->successResponse(
            null,
            'Mot de passe modifié avec succès. Veuillez vous reconnecter.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHASE 2 : MFA, Email Verification, Device Management, Suspicious Activity
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/auth/mfa/enable
     *
     * Commence l'activation MFA et envoie un OTP
     * PHASE 2
     *
     * Body: { type: "email" }
     * Réponse: { success, message, data: { backup_codes: [...] } }
     */
    public function enableMFA(EnableMFARequest $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->mfaService->enableMFA($user, $request->type ?? 'email');

        return $this->successResponse(
            $result,
            'MFA initié. OTP envoyé à ' . $user->email
        );
    }

    /**
     * POST /api/v1/auth/mfa/confirm
     *
     * Confirme l'activation MFA avec l'OTP reçu par email
     * PHASE 2
     *
     * Body: { otp }
     * Réponse: { success, message }
     */
    public function confirmMFA(ConfirmMFARequest $request): JsonResponse
    {
        $user = $request->user();
        $verified = $this->mfaService->confirmMFASetup($user, $request->otp);

        if (!$verified) {
            return $this->errorResponse(
                'Code OTP invalide ou expiré.',
                401
            );
        }

        return $this->successResponse(
            null,
            'Authentification à deux facteurs activée avec succès !'
        );
    }

    /**
     * POST /api/v1/auth/mfa/disable
     *
     * Désactive la MFA pour l'utilisateur
     * PHASE 2
     *
     * Réponse: { success, message }
     */
    public function disableMFA(Request $request): JsonResponse
    {
        $this->mfaService->disableMFA($request->user());

        return $this->successResponse(
            null,
            'Authentification à deux facteurs désactivée.'
        );
    }

    /**
     * GET /api/v1/auth/mfa/status
     *
     * Récupère le statut MFA de l'utilisateur
     * PHASE 2
     *
     * Réponse: { success, message, data: { enabled, type, backup_codes_remaining } }
     */
    public function getMFAStatus(Request $request): JsonResponse
    {
        $status = $this->mfaService->getMFAStatus($request->user());

        return $this->successResponse(
            $status,
            'Statut MFA récupéré.'
        );
    }

    /**
     * POST /api/v1/auth/email/request-verification
     *
     * Demande la vérification d'un email (nouveau ou changement)
     * PHASE 2
     *
     * Body: { email? }
     * Réponse: { success, message }
     */
    public function requestEmailVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        $email = $request->input('email', $user->email);

        $result = $this->emailVerificationService->requestVerification($user, $email);

        return $this->successResponse(
            $result,
            $result['message']
        );
    }

    /**
     * POST /api/v1/auth/email/verify
     *
     * Vérifie l'email avec le code OTP
     * PHASE 2
     *
     * Body: { otp, email }
     * Réponse: { success, message }
     */
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = $request->user();
        $verified = $this->emailVerificationService->verifyEmailWithOTP(
            $user,
            $request->otp,
            $request->email
        );

        if (!$verified) {
            return $this->errorResponse(
                'Code OTP invalide ou expiré.',
                401
            );
        }

        return $this->successResponse(
            null,
            'Email vérifié avec succès !'
        );
    }

    /**
     * GET /api/v1/auth/devices
     *
     * Récupère tous les appareils connectés de l'utilisateur
     * PHASE 2
     *
     * Réponse: { success, message, data: [{ id, device_id, name, device_type, ip_address, country, is_trusted, last_activity }] }
     */
    public function getDevices(Request $request): JsonResponse
    {
        $devices = $this->deviceService->getActiveDevices($request->user());

        return $this->successResponse(
            $devices,
            'Appareils récupérés.'
        );
    }

    /**
     * DELETE /api/v1/auth/devices/{device_id}
     *
     * Déconnecte un appareil spécifique
     * PHASE 2
     *
     * Réponse: { success, message }
     */
    public function removeDevice(Request $request, int $deviceId): JsonResponse
    {
        $removed = $this->deviceService->removeDevice($request->user(), $deviceId);

        if (!$removed) {
            return $this->errorResponse('Appareil non trouvé.', 404);
        }

        return $this->successResponse(
            null,
            'Appareil déconnecté.'
        );
    }

    /**
     * POST /api/v1/auth/devices/logout-all
     *
     * Déconnecte tous les appareils sauf celui actuel
     * PHASE 2
     *
     * Réponse: { success, message }
     */
    public function logoutAllOtherDevices(Request $request): JsonResponse
    {
        $deviceId = $request->input('current_device_id');
        $this->deviceService->removeAllOtherDevices($request->user(), $deviceId);

        return $this->successResponse(
            null,
            'Tous les autres appareils ont été déconnectés.'
        );
    }

    /**
     * GET /api/v1/auth/security/alerts
     *
     * Récupère les alertes de sécurité non révisées
     * PHASE 2
     *
     * Réponse: { success, message, data: [{ id, type, risk, ip, country, details }] }
     */
    public function getSecurityAlerts(Request $request): JsonResponse
    {
        $alerts = $this->suspiciousActivityService->getUnreviewedAlerts($request->user());

        return $this->successResponse(
            $alerts,
            count($alerts) . ' alerte(s) non révisée(s).'
        );
    }

    /**
     * POST /api/v1/auth/security/alerts/{alert_id}/review
     *
     * Marque une alerte de sécurité comme révisée (approuvée ou bloquée)
     * PHASE 2
     *
     * Body: { approved: bool }
     * Réponse: { success, message }
     */
    public function reviewSecurityAlert(Request $request, int $alertId): JsonResponse
    {
        $approved = $request->input('approved', false);
        $this->suspiciousActivityService->reviewAlert($alertId, $approved);

        return $this->successResponse(
            null,
            $approved ? 'Alerte approuvée.' : 'Alerte bloquée.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHASE 3 : Notifications de sécurité + Audit Logs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/auth/security/notification-preferences
     *
     * Récupère les préférences de notifications de sécurité
     * PHASE 3
     *
     * Réponse: { success, message, data: { enabled: bool } }
     */
    public function getNotificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse(
            [
                'enabled' => $user->security_notifications_enabled,
            ],
            'Préférences de notifications récupérées.'
        );
    }

    /**
     * POST /api/v1/auth/security/notification-preferences
     *
     * Met à jour les préférences de notifications
     * PHASE 3
     *
     * Body: { enabled: bool }
     * Réponse: { success, message }
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $enabled = $request->input('enabled', true);
        
        $this->notificationService->updateNotificationPreferences($request->user(), $enabled);

        return $this->successResponse(
            null,
            'Préférences mises à jour. Les notifications de sécurité sont ' . ($enabled ? 'activées' : 'désactivées') . '.'
        );
    }

    /**
     * GET /api/v1/auth/audit/trail
     *
     * Récupère l'historique d'audit complet de l'utilisateur connecté
     * PHASE 3
     *
     * Query params: days=30, limit=100
     * Réponse: { success, message, data: [...] }
     */
    public function getAuditTrail(Request $request): JsonResponse
    {
        $days = $request->input('days', 30);
        $limit = $request->input('limit', 100);

        $trail = $this->auditLogService->getUserAuditTrail($request->user(), $days, $limit);

        return $this->successResponse(
            $trail,
            count($trail) . ' événement(s) d\'audit récupéré(s).'
        );
    }

    /**
     * GET /api/v1/auth/audit/logins
     *
     * Récupère les connexions récentes
     * PHASE 3
     *
     * Query params: limit=20
     * Réponse: { success, message, data: [...] }
     */
    public function getRecentLogins(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 20);
        $logins = $this->auditLogService->getRecentLogins($request->user(), $limit);

        return $this->successResponse(
            $logins,
            'Connexions récentes récupérées.'
        );
    }

    /**
     * GET /api/v1/auth/audit/failed-attempts
     *
     * Récupère les tentatives échouées
     * PHASE 3
     *
     * Query params: days=7, limit=50
     * Réponse: { success, message, data: [...] }
     */
    public function getFailedAttempts(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);
        $limit = $request->input('limit', 50);

        $attempts = $this->auditLogService->getFailedAttempts($request->user(), $days, $limit);

        return $this->successResponse(
            $attempts,
            'Tentatives échouées récupérées.'
        );
    }

    /**
     * GET /api/v1/auth/audit/password-changes
     *
     * Récupère l'historique des changements de mot de passe
     * PHASE 3
     *
     * Query params: limit=20
     * Réponse: { success, message, data: [...] }
     */
    public function getPasswordChanges(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 20);
        $changes = $this->auditLogService->getPasswordChanges($request->user(), $limit);

        return $this->successResponse(
            $changes,
            'Historique des changements récupéré.'
        );
    }

    /**
     * GET /api/v1/auth/audit/token-activity
     *
     * Récupère l'historique d'activité des tokens
     * PHASE 3
     *
     * Query params: limit=50
     * Réponse: { success, message, data: [...] }
     */
    public function getTokenActivity(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 50);
        $activity = $this->auditLogService->getTokenActivity($request->user(), $limit);

        return $this->successResponse(
            $activity,
            'Activité des tokens récupérée.'
        );
    }

    /**
     * GET /api/v1/auth/audit/trail/export
     *
     * Exporte l'audit trail en CSV
     * PHASE 3
     *
     * Query params: days=30
     * Réponse: CSV file
     */
    public function exportAuditTrail(Request $request)
    {
        $days = $request->input('days', 30);
        $csv = $this->auditLogService->exportLogsAsCSV($request->user(), $days);

        return response()
            ->streamDownload(
                function() use ($csv) {
                    echo $csv;
                },
                'audit-trail-' . now()->format('Y-m-d-H-i-s') . '.csv',
                ['Content-Type' => 'text/csv']
            );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHASE 3 : Admin Security Controls
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/auth/admin/security-logs
     *
     * Récupère tous les logs de sécurité (admin only)
     * PHASE 3
     *
     * Query params: days=30, limit=500
     * Réponse: { success, message, data: [...] }
     */
    public function getAllSecurityLogs(Request $request): JsonResponse
    {
        $days = $request->input('days', 30);
        $limit = $request->input('limit', 500);

        $logs = $this->auditLogService->getAllSecurityLogs($days, $limit);

        return $this->successResponse(
            $logs,
            count($logs) . ' log(s) de sécurité récupéré(s).'
        );
    }

    /**
     * GET /api/v1/auth/admin/security-alerts
     *
     * Récupère toutes les alertes de sécurité (admin only)
     * PHASE 3
     *
     * Query params: hours=24, limit=100
     * Réponse: { success, message, data: [...] }
     */
    public function getSystemSecurityAlerts(Request $request): JsonResponse
    {
        $hours = $request->input('hours', 24);
        $limit = $request->input('limit', 100);

        $alerts = $this->auditLogService->getSecurityAlerts($hours, $limit);

        return $this->successResponse(
            $alerts,
            count($alerts) . ' alerte(s) de sécurité système.'
        );
    }

    /**
     * POST /api/v1/auth/admin/enforce-mfa/{user_id}
     *
     * Forcer MFA pour un utilisateur spécifique (admin only)
     * PHASE 3
     *
     * Body: { enabled: bool }
     * Réponse: { success, message }
     */
    public function enforceMFAForUser(Request $request, int $userId): JsonResponse
    {
        $targetUser = User::findOrFail($userId);
        $mfaCredential = $targetUser->mfaCredential;

        if (!$mfaCredential) {
            \App\Models\MFACredential::create([
                'user_id'     => $targetUser->id,
                'mfa_enabled' => true,
                'mfa_type'    => 'email',
            ]);
        } else {
            $mfaCredential->enable();
        }

        \Log::info("MFA forcée pour utilisateur par admin", [
            'enforced_by'  => $request->user()->id,
            'user_id'      => $targetUser->id,
        ]);

        return $this->successResponse(
            null,
            "MFA activée pour {$targetUser->nom}."
        );
    }
}

