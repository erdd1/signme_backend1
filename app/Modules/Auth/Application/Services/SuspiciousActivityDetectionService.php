<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\SuspiciousActivity;
use App\Models\User;

/**
 * SuspiciousActivityDetectionService
 *
 * Détecte et enregistre les activités suspectes
 * - Multiples IPs
 * - Pays différents
 * - Trop de requêtes
 * - Appareils inconnus
 *
 * @package App\Modules\Auth\Application\Services
 */
class SuspiciousActivityDetectionService
{
    /**
     * Analyser une connexion pour détecter activité suspecte
     */
    public function analyzeLogin(User $user, string $ipAddress, ?string $deviceId = null): array
    {
        $suspicious = [];
        $ipCountry = $this->geolocateIP($ipAddress);

        // 1. Vérifier historique logins
        $recentLogins = \App\Models\SecurityLog::where('user_id', $user->id)
                                               ->where('action', 'login')
                                               ->where('status', 'success')
                                               ->where('created_at', '>', now()->subHour())
                                               ->get();

        // Multiples IPs en courte période
        $recentIPs = $recentLogins->pluck('details.ip_address')->unique()->count();
        if ($recentIPs > 2) {
            $suspicious[] = $this->recordActivity(
                $user->id,
                'multiple_ips',
                'medium',
                $ipAddress,
                $ipCountry,
                $deviceId,
                ['recent_ips' => $recentIPs]
            );
        }

        // Pays différents en courte période
        if ($recentLogins->count() > 0) {
            $previousLogins = \App\Models\DeviceSession::where('user_id', $user->id)
                                                       ->where('created_at', '>', now()->subDay())
                                                       ->pluck('country')
                                                       ->unique();

            if ($previousLogins->count() > 0 && !$previousLogins->contains($ipCountry)) {
                $suspicious[] = $this->recordActivity(
                    $user->id,
                    'different_countries',
                    'high',
                    $ipAddress,
                    $ipCountry,
                    $deviceId,
                    ['previous_country' => $previousLogins->first()]
                );
            }
        }

        // 2. Vérifier rate limiting excessif
        $failedLogins = \App\Models\LoginAttempt::where('email', $user->email)
                                                 ->where('created_at', '>', now()->subHour())
                                                 ->where('failed_count', '>', 0)
                                                 ->count();

        if ($failedLogins > 10) {
            $suspicious[] = $this->recordActivity(
                $user->id,
                'brute_force',
                'critical',
                $ipAddress,
                $ipCountry,
                $deviceId,
                ['failed_attempts' => $failedLogins]
            );
        }

        // 3. Connexion à une heure inhabituelle
        $hour = now()->hour;
        if ($hour < 6 || $hour > 23) { // Entre minuit et 6h
            $suspicious[] = $this->recordActivity(
                $user->id,
                'unusual_time',
                'low',
                $ipAddress,
                $ipCountry,
                $deviceId,
                ['hour' => $hour]
            );
        }

        return $suspicious;
    }

    /**
     * Enregistrer une activité suspecte
     */
    public function recordActivity(
        int $userId,
        string $type,
        string $riskLevel,
        string $ipAddress,
        ?string $country = null,
        ?string $deviceId = null,
        ?array $details = null
    ): SuspiciousActivity {
        return SuspiciousActivity::create([
            'user_id'       => $userId,
            'activity_type' => $type,
            'risk_level'    => $riskLevel,
            'ip_address'    => $ipAddress,
            'country'       => $country,
            'device_id'     => $deviceId,
            'details'       => $details,
            'is_blocked'    => false,
            'is_reviewed'   => false,
        ]);
    }

    /**
     * Obtenir les alertes non révisées pour un utilisateur
     */
    public function getUnreviewedAlerts(User $user): array
    {
        return SuspiciousActivity::forUser($user->id)
                                 ->unreviewed()
                                 ->recent()
                                 ->get()
                                 ->map(fn($alert) => [
                                     'id'           => $alert->id,
                                     'type'         => $alert->activity_type,
                                     'risk'         => $alert->getRiskLabel(),
                                     'ip'           => $alert->ip_address,
                                     'country'      => $alert->country,
                                     'details'      => $alert->details,
                                     'created_at'   => $alert->created_at,
                                 ])
                                 ->toArray();
    }

    /**
     * Marquer une alerte comme révisée et approuvée/bloquée
     */
    public function reviewAlert(int $alertId, bool $approved): void
    {
        $alert = SuspiciousActivity::find($alertId);
        if ($alert) {
            $alert->markAsReviewed($approved);
        }
    }

    /**
     * Géolocaliser une IP (stub)
     */
    private function geolocateIP(string $ip): ?string
    {
        // TODO: Intégrer MaxMind GeoIP
        return null;
    }
}
