<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Événement diffusé quand les compteurs de badges changent pour un utilisateur.
 *
 * Canal cible : private-user.{userId}
 *
 * Payload Flutter :
 * {
 *   "annonces":             1,
 *   "meditations":          2,
 *   "evenements":           0,
 *   "historique":           0,
 *   "historique_signatures": 1
 * }
 */
class BadgeUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $annonces           = 0,
        public readonly int $meditations        = 0,
        public readonly int $evenements         = 0,
        public readonly int $historique          = 0,
        public readonly int $historiqueSignatures = 0,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'badge.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'annonces'              => $this->annonces,
            'meditations'           => $this->meditations,
            'evenements'            => $this->evenements,
            'historique'            => $this->historique,
            'historique_signatures' => $this->historiqueSignatures,
        ];
    }
}
