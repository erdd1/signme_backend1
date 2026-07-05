<?php

namespace App\Modules\Push\Application\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Service PushNotificationService — Envoi de notifications push via Firebase
 * Cloud Messaging (FCM). Source unique pour tous les modules qui déclenchent
 * un push (Publication, Evenement, anniversaires) — aucune duplication de
 * l'appel FCM lui-même.
 *
 * Nettoie automatiquement les jetons invalides/désinstallés après chaque
 * envoi (l'utilisateur a désinstallé l'app ou changé d'appareil).
 */
class PushNotificationService
{
    private const CHUNK_SIZE = 500; // limite FCM par appel multicast

    private ?Messaging $messaging = null;

    private function messaging(): Messaging
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        $credentials = config('services.firebase.credentials');
        $path = str_starts_with($credentials, '/') || preg_match('/^[A-Za-z]:\\\\/', $credentials)
            ? $credentials
            : base_path($credentials);

        return $this->messaging = (new Factory())
            ->withServiceAccount($path)
            ->createMessaging();
    }

    /**
     * Envoie un push à une liste de jetons d'appareils bruts.
     *
     * @param array<string> $tokens
     * @param array<string, string> $data Payload additionnel (ex. type, id, route)
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        foreach (array_chunk($tokens, self::CHUNK_SIZE) as $chunk) {
            try {
                $report = $this->messaging()->sendMulticast($message, $chunk);

                $obsolete = array_merge($report->unknownTokens(), $report->invalidTokens());
                if (!empty($obsolete)) {
                    DeviceToken::whereIn('token', $obsolete)->delete();
                }
            } catch (\Throwable $e) {
                Log::error('PushNotificationService: échec envoi FCM', [
                    'error' => $e->getMessage(),
                    'title' => $title,
                ]);
            }
        }
    }

    /**
     * Envoie un push à tous les appareils enregistrés d'une liste
     * d'utilisateurs (dédupliqué).
     *
     * @param Collection<int, \App\Models\User>|array<\App\Models\User> $users
     * @param array<string, string> $data
     */
    public function sendToUsers(iterable $users, string $title, string $body, array $data = []): void
    {
        $userIds = collect($users)->pluck('id')->unique()->values();
        if ($userIds->isEmpty()) {
            return;
        }

        $tokens = DeviceToken::whereIn('userId', $userIds)->pluck('token')->all();
        $this->sendToTokens($tokens, $title, $body, $data);
    }
}
