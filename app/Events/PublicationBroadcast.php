<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Événement diffusé en temps réel lors d'une modification de publication.
 *
 * Déclenché par le PublicationController sur :
 *   - Création  (store)
 *   - Mise à jour (update)
 *   - Suppression (destroy)
 *
 * Canal cible : private-church.{churchId}
 *
 * Payload (reçu côté Flutter) :
 * {
 *   "action":    "created" | "updated" | "deleted",
 *   "publication_id": 42,
 *   "titre":     "…",
 *   "type":      "annonce" | "meditation"
 * }
 */
class PublicationBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $churchId,
        public readonly string $action,       // 'created' | 'updated' | 'deleted'
        public readonly int    $publicationId,
        public readonly string $titre         = '',
        public readonly string $type          = '',
    ) {}

    /**
     * Canal privé de l'église — seuls ses membres l'écoutent.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('church.' . $this->churchId),
        ];
    }

    /**
     * Nom de l'événement côté client Pusher/Reverb.
     */
    public function broadcastAs(): string
    {
        return 'publication.updated';
    }

    /**
     * Données envoyées dans le payload WebSocket.
     */
    public function broadcastWith(): array
    {
        return [
            'action'         => $this->action,
            'publication_id' => $this->publicationId,
            'titre'          => $this->titre,
            'type'           => $this->type,
        ];
    }
}
