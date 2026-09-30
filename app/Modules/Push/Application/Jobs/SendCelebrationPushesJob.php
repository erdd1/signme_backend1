<?php

namespace App\Modules\Push\Application\Jobs;

use App\Models\Celebration;
use App\Models\CelebrationType;
use App\Models\Church;
use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Push\Application\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job planifié — Module Célébrations. Généralise l'ancien
 * SendBirthdayPushesJob à 9 occasions (anniversaire, Nouvel An, Noël,
 * Saint-Valentin, journée de la femme, fête du travail, Pâques, fête de
 * l'Unité, fête de la Jeunesse — voir CelebrationTypeSeeder).
 *
 * Envoie à 6h du matin, heure LOCALE de l'utilisateur (fuseau IANA capturé
 * à la connexion), jamais une heure fixe pour tout le monde. Tourne toutes
 * les 15 minutes ; l'unicité (userId, celebrationTypeId, occursOn) sur la
 * table `celebrations` garantit un seul envoi par occasion et par jour.
 *
 * À exécuter via scheduler : Schedule::job(...)->everyFifteenMinutes();
 */
class SendCelebrationPushesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PushNotificationService $push): void
    {
        $types = CelebrationType::where('active', true)->get();
        if ($types->isEmpty()) {
            return;
        }

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
            ->where('actif', true)
            ->get(['id', 'nom', 'sexe', 'dateNaissance', 'churchId']);

        if ($users->isEmpty()) {
            return;
        }

        $churches = Church::whereIn('id', $users->pluck('churchId')->unique())->get()->keyBy('id');

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

            foreach ($types as $type) {
                $this->maybeSend($push, $type, $user, $churches->get($user->churchId), $localNow);
            }
        }
    }

    private function maybeSend(
        PushNotificationService $push,
        CelebrationType $type,
        User $user,
        ?Church $church,
        Carbon $localNow,
    ): void {
        if (!$this->occursToday($type, $user, $localNow)) {
            return;
        }

        if ($type->genderFilter !== null && $user->sexe !== $type->genderFilter) {
            return;
        }

        $exists = Celebration::where('userId', $user->id)
            ->where('celebrationTypeId', $type->id)
            ->whereDate('occursOn', $localNow->toDateString())
            ->exists();
        if ($exists) {
            return; // déjà envoyé aujourd'hui pour cette occasion
        }

        $celebration = Celebration::create([
            'userId' => $user->id,
            'churchId' => $user->churchId,
            'celebrationTypeId' => $type->id,
            'occursOn' => $localNow->toDateString(),
        ]);

        $push->sendToUsers(
            [$user],
            $type->titre,
            $type->renderMessage($user, $church),
            ['type' => 'celebration', 'id' => $celebration->uuid],
        );

        Log::info('SendCelebrationPushesJob: célébration notifiée', [
            'user_id' => $user->id,
            'type' => $type->key,
            'timezone' => $localNow->timezoneName,
        ]);
    }

    private function occursToday(CelebrationType $type, User $user, Carbon $localNow): bool
    {
        return match ($type->dateRuleType) {
            CelebrationType::RULE_BIRTHDAY => $user->dateNaissance !== null
                && $user->dateNaissance->format('m-d') === $localNow->format('m-d'),
            CelebrationType::RULE_FIXED => $type->fixedMonth === (int) $localNow->format('n')
                && $type->fixedDay === (int) $localNow->format('j'),
            CelebrationType::RULE_EASTER => $this->easterMonthDay((int) $localNow->format('Y')) === $localNow->format('m-d'),
            default => false,
        };
    }

    /**
     * Date de Pâques (dimanche) pour une année donnée, au format "m-d".
     * Algorithme de Meeus/Jones/Butcher (grégorien) en PHP pur — pas de
     * dépendance à l'extension `calendar` (souvent absente sous Windows),
     * contrairement à la fonction native `easter_date()`.
     */
    private function easterMonthDay(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $numerator = $h + $l - 7 * $m + 114;
        $month = intdiv($numerator, 31);
        $day = ($numerator % 31) + 1;

        return sprintf('%02d-%02d', $month, $day);
    }
}
