<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\DeviceSession;
use App\Models\User;

/**
 * DeviceManagementService
 *
 * Gère les appareils connectés et détecte activité suspecte
 * Stocke: device_id, IP, OS, navigateur, géolocalisation, etc.
 *
 * @package App\Modules\Auth\Application\Services
 */
class DeviceManagementService
{
    /**
     * Enregistrer ou mettre à jour une session appareil
     *
     * IMPORTANT: Utilise updateOrCreate() pour éviter Duplicate Entry errors
     * Si le device_id existe déjà pour cet utilisateur, on met à jour au lieu de créer.
     *
     * @param User $user
     * @param string|null $deviceId
     * @param array $deviceInfo
     * @return DeviceSession
     */
    public function registerDevice(
        User $user,
        ?string $deviceId = null,
        array $deviceInfo = []
    ): DeviceSession {
        $ipAddress = request()->ip();
        $userAgent = request()->header('User-Agent') ?? '';

        // Parser automatiquement les infos du device à partir du User-Agent si pas fournies
        if (empty($deviceInfo)) {
            $deviceInfo = $this->parseUserAgent($userAgent);
        }

        // Clé d'unicité
        $uniqueKey = [
            'user_id' => $user->id,
            'device_id' => $deviceId,
        ];

        // Données à mettre à jour/créer
        $data = [
            'device_name'      => $deviceInfo['device_name'] ?? $this->detectDeviceName($userAgent),
            'device_type'      => $deviceInfo['device_type'] ?? $this->detectDeviceType($userAgent),
            'os'               => $deviceInfo['os'] ?? null,
            'os_version'       => $deviceInfo['os_version'] ?? null,
            'app_version'      => $deviceInfo['app_version'] ?? null,
            'browser'          => $deviceInfo['browser'] ?? null,
            'ip_address'       => $ipAddress,
            'country'          => $deviceInfo['country'] ?? $this->geolocateIP($ipAddress),
            'city'             => $deviceInfo['city'] ?? null,
            'user_agent'       => $userAgent,
            'is_trusted'       => $deviceInfo['is_trusted'] ?? false,
            'last_activity_at' => now(),
        ];

        // updateOrCreate() au lieu de create()
        // Cela évite les erreurs Duplicate Entry si device_id existe
        return DeviceSession::updateOrCreate($uniqueKey, $data);
    }

    /**
     * Détecter le type d'appareil à partir du User-Agent
     * mobile|tablet|web
     */
    private function detectDeviceType(string $userAgent): string
    {
        if (preg_match('/mobile|android|iphone|ipod|windows phone/i', $userAgent)) {
            return 'mobile';
        }
        if (preg_match('/ipad|android.*tablet|kindle/i', $userAgent)) {
            return 'tablet';
        }
        return 'web';
    }

    /**
     * Détecter le nom de l'appareil à partir du User-Agent
     * ex: iPhone 12, Samsung Galaxy, etc.
     */
    private function detectDeviceName(string $userAgent): string
    {
        // iOS devices
        if (preg_match('/iPhone\s*([\d,]+)/i', $userAgent, $matches)) {
            $version = str_replace(',', '.', $matches[1]);
            return "iPhone {$version}";
        }
        if (preg_match('/iPad\s*([\d,]+)/i', $userAgent, $matches)) {
            $version = str_replace(',', '.', $matches[1]);
            return "iPad {$version}";
        }

        // Android devices - extraire du User-Agent si possible
        if (preg_match('/Android.*Build\/(.*?)\s/i', $userAgent, $matches)) {
            return "Android Device ({$matches[1]})";
        }

        // Fallback pour autres appareils
        if (preg_match('/Windows/i', $userAgent)) {
            return 'Windows Device';
        }
        if (preg_match('/Macintosh|Mac OS/i', $userAgent)) {
            return 'Mac Device';
        }

        return 'Unknown Device';
    }

    /**
     * Parser les informations du User-Agent
     * Retourne: device_type, os, os_version, browser, etc.
     */
    private function parseUserAgent(string $userAgent): array
    {
        $info = [
            'device_type' => $this->detectDeviceType($userAgent),
            'device_name' => $this->detectDeviceName($userAgent),
        ];

        // Détecter l'OS
        if (preg_match('/iPhone|iPad|Mac OS/i', $userAgent)) {
            $info['os'] = 'iOS';
            if (preg_match('/OS\s+([\d_]+)/i', $userAgent, $m)) {
                $info['os_version'] = str_replace('_', '.', $m[1]);
            }
        } elseif (preg_match('/Android/i', $userAgent)) {
            $info['os'] = 'Android';
            if (preg_match('/Android\s+([\d.]+)/i', $userAgent, $m)) {
                $info['os_version'] = $m[1];
            }
        } elseif (preg_match('/Windows NT/i', $userAgent)) {
            $info['os'] = 'Windows';
            if (preg_match('/Windows NT\s+([\d.]+)/i', $userAgent, $m)) {
                $info['os_version'] = $m[1];
            }
        } elseif (preg_match('/Mac OS/i', $userAgent)) {
            $info['os'] = 'macOS';
            if (preg_match('/Mac OS X\s+([\d_]+)/i', $userAgent, $m)) {
                $info['os_version'] = str_replace('_', '.', $m[1]);
            }
        }

        // Détecter le navigateur (pour web)
        if (preg_match('/Chrome\/(\S+)/i', $userAgent, $m)) {
            $info['browser'] = 'Chrome';
        } elseif (preg_match('/Safari\/(\S+)/i', $userAgent, $m)) {
            $info['browser'] = 'Safari';
        } elseif (preg_match('/Firefox\/(\S+)/i', $userAgent, $m)) {
            $info['browser'] = 'Firefox';
        }

        return $info;
    }

    /**
     * Marquer un appareil comme approuvé/approuvé de confiance
     */
    public function markDeviceAsTrusted(User $user, ?string $deviceId): void
    {
        if (!$deviceId) {
            return;
        }

        DeviceSession::where('user_id', $user->id)
                     ->where('device_id', $deviceId)
                     ->update(['is_trusted' => true]);
    }

    /**
     * Obtenir tous les appareils actifs d'un utilisateur
     */
    public function getActiveDevices(User $user): array
    {
        return DeviceSession::forUser($user->id)
                             ->active()
                             ->latest('last_activity_at')
                             ->get()
                             ->map(fn($device) => [
                                 'id'           => $device->id,
                                 'device_id'    => $device->device_id,
                                 'name'         => $device->device_name ?? $device->getDeviceLabel(),
                                 'device_type'  => $device->device_type,
                                 'ip_address'   => $device->ip_address,
                                 'country'      => $device->country,
                                 'os'           => $device->os,
                                 'browser'      => $device->browser,
                                 'is_trusted'   => $device->is_trusted,
                                 'last_activity' => $device->last_activity_at,
                             ])
                             ->toArray();
    }

    /**
     * Supprimer un appareil (déconnecter)
     */
    public function removeDevice(User $user, int $deviceId): bool
    {
        return DeviceSession::where('user_id', $user->id)
                             ->where('id', $deviceId)
                             ->delete() > 0;
    }

    /**
     * Supprimer tous les appareils sauf celui actuel
     */
    public function removeAllOtherDevices(User $user, ?string $currentDeviceId): void
    {
        if ($currentDeviceId) {
            DeviceSession::where('user_id', $user->id)
                         ->where('device_id', '!=', $currentDeviceId)
                         ->delete();
        } else {
            DeviceSession::where('user_id', $user->id)->delete();
        }
    }

    /**
     * Détecter appareils suspects
     * - Nouvelle IP
     * - Nouveau pays
     * - Trop de sessions
     */
    public function detectSuspiciousDevices(User $user, string $currentIP): array
    {
        $suspicious = [];

        // 1. Vérifier si IP différente
        $previousDevices = DeviceSession::forUser($user->id)->get();
        $previousIPs = $previousDevices->pluck('ip_address')->unique();

        if ($previousIPs->count() > 0 && !$previousIPs->contains($currentIP)) {
            $suspicious[] = [
                'type'  => 'new_ip',
                'message' => 'Connexion depuis une nouvelle adresse IP',
                'ip'    => $currentIP,
            ];
        }

        // 2. Vérifier si trop d'appareils
        if ($previousDevices->count() > 5) {
            $suspicious[] = [
                'type'    => 'excessive_devices',
                'message' => 'Plus de 5 appareils actifs détectés',
                'count'   => $previousDevices->count(),
            ];
        }

        return $suspicious;
    }

    /**
     * Géolocaliser une IP (stub - intégrer MaxMind GeoIP)
     */
    private function geolocateIP(string $ip): ?string
    {
        // TODO: Intégrer MaxMind GeoIP ou service similaire
        // Pour maintenant, retourner null
        return null;
    }
}
