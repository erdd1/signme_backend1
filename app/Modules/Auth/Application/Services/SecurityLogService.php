<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\SecurityLog;
use App\Models\User;

/**
 * SecurityLogService
 *
 * Gère la journalisation sécurisée des événements d'authentification.
 * Sanitise les données sensibles (JAMAIS passwords, tokens, authorization headers)
 *
 * @package App\Modules\Auth\Application\Services
 */
class SecurityLogService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('security.logging');
    }

    /**
     * Enregistrer un événement de sécurité
     *
     * @param string $action (login|logout|failed_login|password_reset|token_refresh|blocked|suspicious_activity)
     * @param string $status (success|failed|blocked|alert)
     * @param User|int|null $user
     * @param array $details Détails additionnels (sanitisés automatiquement)
     * @param string|null $ipAddress
     * @param string|null $deviceId
     */
    public function log(
        string $action,
        string $status = 'success',
        User|int|null $user = null,
        array $details = [],
        ?string $ipAddress = null,
        ?string $deviceId = null
    ): SecurityLog {
        if (!$this->config['enabled']) {
            return new SecurityLog(); // Logging désactivé
        }

        // Extraire user_id
        $userId = $user instanceof User ? $user->id : $user;

        // Sanitiser les détails
        $sanitized = $this->sanitizeDetails($details);

        return SecurityLog::recordAction(
            userId: $userId,
            action: $action,
            status: $status,
            ipAddress: $ipAddress ?? request()->ip(),
            details: $sanitized,
            deviceId: $deviceId,
            userAgent: request()->header('User-Agent')
        );
    }

    /**
     * Log : Connexion réussie
     */
    public function logSuccessfulLogin(User $user, ?string $deviceId = null): void
    {
        $this->log(
            action: 'login',
            status: 'success',
            user: $user,
            details: [
                'role' => $user->role,
                'email' => $user->email, // Email OK (pas sensible au même titre que password)
            ],
            deviceId: $deviceId
        );
    }

    /**
     * Log : Tentative de connexion échouée
     */
    public function logFailedLogin(string $email, ?string $reason = null): void
    {
        $this->log(
            action: 'failed_login',
            status: 'failed',
            user: null,
            details: [
                'email'  => $email,
                'reason' => $reason, // 'invalid_credentials', 'account_inactive', 'account_blocked', etc
            ]
        );
    }

    /**
     * Log : Compte bloqué après trop d'essais
     */
    public function logLoginBlocked(string $email, int $remainingMinutes): void
    {
        $this->log(
            action: 'blocked',
            status: 'blocked',
            user: null,
            details: [
                'email'               => $email,
                'reason'              => 'too_many_failed_attempts',
                'remaining_minutes'   => $remainingMinutes,
            ]
        );
    }

    /**
     * Log : Déconnexion
     */
    public function logLogout(User $user): void
    {
        $this->log(
            action: 'logout',
            status: 'success',
            user: $user,
            details: ['email' => $user->email]
        );
    }

    /**
     * Log : Changement de mot de passe
     */
    public function logPasswordReset(User $user, bool $success = true): void
    {
        $this->log(
            action: 'password_reset',
            status: $success ? 'success' : 'failed',
            user: $user,
            details: ['email' => $user->email]
        );
    }

    /**
     * Log : Rafraîchissement du token
     */
    public function logTokenRefresh(User $user, ?string $deviceId = null): void
    {
        $this->log(
            action: 'token_refresh',
            status: 'success',
            user: $user,
            details: ['email' => $user->email],
            deviceId: $deviceId
        );
    }

    /**
     * Log : Activité suspecte détectée
     */
    public function logSuspiciousActivity(
        ?User $user,
        string $reason,
        array $details = []
    ): void {
        $this->log(
            action: 'suspicious_activity',
            status: 'alert',
            user: $user,
            details: array_merge(['reason' => $reason], $details)
        );
    }

    /**
     * Sanitiser les détails pour éviter les données sensibles
     * Ne JAMAIS logger : passwords, tokens, authorization headers
     *
     * @param array $details
     * @return array
     */
    private function sanitizeDetails(array $details): array
    {
        $fieldsToRedact = $this->config['sanitize_fields'] ?? [];

        foreach ($fieldsToRedact as $field) {
            if (isset($details[$field])) {
                $details[$field] = '[REDACTED]';
            }
        }

        return $details;
    }

    /**
     * Récupérer l'audit trail d'un utilisateur
     *
     * @param User $user
     * @param int $days
     * @param int $limit
     */
    public function getAuditTrail(User $user, int $days = 30, int $limit = 50)
    {
        return SecurityLog::forUser($user->id)
                          ->where('created_at', '>', now()->subDays($days))
                          ->latest()
                          ->limit($limit)
                          ->get();
    }

    /**
     * Récupérer les alertes de sécurité récentes
     *
     * @param int $hours
     */
    public function getRecentAlerts(int $hours = 24)
    {
        return SecurityLog::alerts()
                          ->where('created_at', '>', now()->subHours($hours))
                          ->latest()
                          ->get();
    }
}
