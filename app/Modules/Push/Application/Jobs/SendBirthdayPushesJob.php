<?php

namespace App\Modules\Push\Application\Jobs;

use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Push\Application\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job planifié — Souhaite l'anniversaire aux utilisateurs à 6h du matin,
 * heure LOCALE de leur appareil (fuseau IANA capturé à la connexion, ex.
 * "Africa/Douala", "Europe/Paris"), pas une heure fixe pour tout le monde.
 *
 * Tourne toutes les 15 minutes ; `users.lastBirthdayPushAt` garantit qu'un
 * seul envoi a lieu par jour même si le job passe plusieurs fois dans la
 * fenêtre de 6h-7h locale d'un utilisateur.
 *
 * À exécuter via scheduler : Schedule::job(...)->everyFifteenMinutes();
 */
class SendBirthdayPushesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PushNotificationService $push): void
    {
        // Fuseau le plus récent connu par utilisateur (dernier appareil actif).
        $latestTokenIds = DeviceToken::select(DB::raw('MAX(id) as id'))
            ->whereNotNull('timezone')
            ->groupBy('userId')
            ->pluck('id');

        $userTimezones = DeviceToken::whereIn('id', $latestTokenIds)
            ->get(['userId', 'timezone'])
            ->keyBy('userId');

        if ($userTimezones->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $userTimezones->keys())
            ->whereNotNull('dateNaissance')
            ->where('actif', true)
            ->get(['id', 'nom', 'dateNaissance', 'lastBirthdayPushAt']);

        foreach ($users as $user) {
            $timezone = $userTimezones->get($user->id)?->timezone;
            if (!$timezone) {
                continue;
            }

            try {
                $localNow = now($timezone);
            } catch (\Throwable $e) {
                continue; // fuseau invalide/inconnu — ignoré silencieusement
            }

            // Fenêtre 6h-7h heure locale uniquement.
            if ($localNow->hour !== 6) {
                continue;
            }

            if ($user->dateNaissance->format('m-d') !== $localNow->format('m-d')) {
                continue;
            }

            if ($user->lastBirthdayPushAt?->isSameDay($localNow)) {
                continue; // déjà envoyé aujourd'hui
            }

            $push->sendToUsers(
                [$user],
                'Joyeux anniversaire 🎉',
                "Toute l'équipe SignMe vous souhaite un très joyeux anniversaire, {$user->nom} !",
                ['type' => 'anniversaire'],
            );

            $user->update(['lastBirthdayPushAt' => $localNow]);

            Log::info('SendBirthdayPushesJob: anniversaire notifié', [
                'user_id'  => $user->id,
                'timezone' => $timezone,
            ]);
        }
    }
}
