<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Models\SecurityLog;

/**
 * AuditLogService
 *
 * Récupère et formate les logs d'audit pour la sécurité
 * PHASE 3
 *
 * @package App\Modules\Auth\Application\Services
 */
class AuditLogService
{
    /**
     * Obtenir l'historique d'audit complet pour un utilisateur
     */
    public function getUserAuditTrail(User $user, int $days = 30, int $limit = 100): array
    {
        $logs = SecurityLog::where('user_id', $user->id)
                           ->where('created_at', '>', now()->subDays($days))
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => $this->formatLog($log))->toArray();
    }

    /**
     * Obtenir les connexions récentes d'un utilisateur
     */
    public function getRecentLogins(User $user, int $limit = 20): array
    {
        $logs = SecurityLog::where('user_id', $user->id)
                           ->where('action', 'login')
                           ->where('status', 'success')
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
            'device_id'    => $log->device_id,
            'user_agent'   => $log->details['user_agent'] ?? null,
        ])->toArray();
    }

    /**
     * Obtenir les tentatives échouées d'un utilisateur
     */
    public function getFailedAttempts(User $user, int $days = 7, int $limit = 50): array
    {
        $logs = SecurityLog::where('user_id', $user->id)
                           ->where('status', 'failed')
                           ->where('created_at', '>', now()->subDays($days))
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'action'       => $log->action,
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
            'reason'       => $log->details['reason'] ?? 'unknown',
        ])->toArray();
    }

    /**
     * Obtenir les changements de mot de passe
     */
    public function getPasswordChanges(User $user, int $limit = 20): array
    {
        $logs = SecurityLog::where('user_id', $user->id)
                           ->whereIn('action', ['password_reset', 'password_changed'])
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'action'       => $log->action === 'password_reset' ? 'Reset' : 'Changed',
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
        ])->toArray();
    }

    /**
     * Obtenir les activités de tokens
     */
    public function getTokenActivity(User $user, int $limit = 50): array
    {
        $logs = SecurityLog::where('user_id', $user->id)
                           ->where('action', 'token_refresh')
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
            'device_id'    => $log->device_id,
        ])->toArray();
    }

    /**
     * Obtenir tous les logs de sécurité (admin only)
     */
    public function getAllSecurityLogs(int $days = 30, int $limit = 500): array
    {
        $logs = SecurityLog::where('created_at', '>', now()->subDays($days))
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'user_id'      => $log->user_id,
            'user_name'    => $log->user?->nom ?? 'Unknown',
            'action'       => $log->action,
            'status'       => $log->status,
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
        ])->toArray();
    }

    /**
     * Obtenir les alertes de sécurité (admin only)
     */
    public function getSecurityAlerts(int $hours = 24, int $limit = 100): array
    {
        $logs = SecurityLog::where('status', 'alert')
                           ->where('created_at', '>', now()->subHours($hours))
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'user_id'      => $log->user_id,
            'user_name'    => $log->user?->nom ?? 'Unknown',
            'action'       => $log->action,
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
            'details'      => $log->details,
        ])->toArray();
    }

    /**
     * Obtenir toutes les connexions réussies, tous utilisateurs confondus (admin only)
     */
    public function getAllLogins(int $days = 30, int $limit = 100): array
    {
        $logs = SecurityLog::where('action', 'login')
                           ->where('status', 'success')
                           ->where('created_at', '>', now()->subDays($days))
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'user_id'      => $log->user_id,
            'user_name'    => $log->user?->nom ?? 'Unknown',
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
            'device_id'    => $log->device_id,
        ])->toArray();
    }

    /**
     * Obtenir toutes les tentatives échouées, tous utilisateurs confondus (admin only)
     */
    public function getAllFailedAttempts(int $days = 7, int $limit = 100): array
    {
        $logs = SecurityLog::where('status', 'failed')
                           ->where('created_at', '>', now()->subDays($days))
                           ->orderBy('created_at', 'desc')
                           ->limit($limit)
                           ->get();

        return $logs->map(fn($log) => [
            'id'           => $log->id,
            'user_id'      => $log->user_id,
            'user_name'    => $log->user?->nom ?? 'Unknown',
            'action'       => $log->action,
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
            'reason'       => $log->details['reason'] ?? 'unknown',
        ])->toArray();
    }

    /**
     * Exporter les logs en CSV (admin only)
     */
    public function exportLogsAsCSV(User $user, int $days = 30): string
    {
        $logs = $this->getUserAuditTrail($user, $days);
        
        $csv = "ID,Timestamp,Action,Status,IP Address,Device ID,Details\n";
        
        foreach ($logs as $log) {
            $csv .= implode(',', [
                $log['id'],
                $log['timestamp'],
                $log['action'],
                $log['status'],
                $log['ip_address'] ?? '',
                $log['device_id'] ?? '',
                '"' . ($log['details'] ?? '') . '"',
            ]) . "\n";
        }

        return $csv;
    }

    /**
     * Formater un log pour affichage
     */
    private function formatLog(SecurityLog $log): array
    {
        return [
            'id'           => $log->id,
            'action'       => $log->action,
            'status'       => $log->status,
            'timestamp'    => $log->created_at->format('Y-m-d H:i:s'),
            'ip_address'   => $log->details['ip_address'] ?? null,
            'device_id'    => $log->device_id,
            'details'      => $log->details,
        ];
    }
}
