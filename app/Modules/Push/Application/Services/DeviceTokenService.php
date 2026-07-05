<?php

namespace App\Modules\Push\Application\Services;

use App\Models\DeviceToken;
use App\Models\User;

/**
 * Service DeviceToken — Enregistrement des jetons FCM par appareil.
 *
 * Un jeton FCM correspond à une seule installation d'app. S'il réapparaît
 * pour un autre utilisateur (nouveau login sur le même appareil), il est
 * réassigné plutôt que dupliqué — évite d'envoyer un push au mauvais
 * utilisateur après un changement de compte sur le même téléphone.
 */
class DeviceTokenService
{
    public function register(User $user, string $token, string $platform, ?string $timezone): DeviceToken
    {
        return DeviceToken::updateOrCreate(
            ['token' => $token],
            [
                'userId'     => $user->id,
                'platform'   => $platform,
                'timezone'   => $timezone,
                'lastSeenAt' => now(),
            ]
        );
    }

    /**
     * Supprime le jeton d'un appareil (déconnexion) — n'affecte pas les
     * autres appareils de l'utilisateur.
     */
    public function unregister(User $user, string $token): void
    {
        DeviceToken::where('userId', $user->id)->where('token', $token)->delete();
    }
}
