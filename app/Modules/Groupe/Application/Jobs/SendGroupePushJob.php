<?php

namespace App\Modules\Groupe\Application\Jobs;

use App\Models\Groupe;
use App\Models\User;
use App\Modules\Push\Application\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job — Notifie (push FCM) les membres actifs d'un groupe, auteur exclu.
 * Même gabarit que SendNewContentPushJob (église) ; l'envoi lui-même vit
 * uniquement dans PushNotificationService.
 */
class SendGroupePushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array<string, string> $data payload de navigation au tap */
    public function __construct(
        public int $groupeId,
        public int $excludeUserId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(PushNotificationService $push): void
    {
        $groupe = Groupe::find($this->groupeId);
        if (!$groupe) {
            return;
        }

        $destinataires = $groupe->membres()
            ->where('users.id', '!=', $this->excludeUserId)
            ->where('users.actif', true)
            ->get(['users.id']);

        $push->sendToUsers($destinataires, $this->title, $this->body, $this->data);
    }
}
