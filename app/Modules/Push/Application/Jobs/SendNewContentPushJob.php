<?php

namespace App\Modules\Push\Application\Jobs;

use App\Models\User;
use App\Modules\Push\Application\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job — Notifie (push FCM) tous les membres actifs d'une église qu'un
 * nouveau contenu (publication ou événement) est disponible, à l'exclusion
 * de son auteur. Réutilisé par les modules Publication et Evenement — la
 * logique d'envoi elle-même vit uniquement dans PushNotificationService.
 */
class SendNewContentPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<string, string> $data Payload additionnel (ex. type, id) pour la navigation au tap
     */
    public function __construct(
        public int $churchId,
        public int $excludeUserId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(PushNotificationService $push): void
    {
        $destinataires = User::where('churchId', $this->churchId)
            ->where('id', '!=', $this->excludeUserId)
            ->where('actif', true)
            ->get(['id']);

        $push->sendToUsers($destinataires, $this->title, $this->body, $this->data);
    }
}
