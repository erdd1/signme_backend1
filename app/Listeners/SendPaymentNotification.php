<?php

namespace App\Listeners;

use App\Events\PaymentCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Class SendPaymentNotification
 * 
 * Listener qui envoie une notification quand un paiement est complété
 */
class SendPaymentNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PaymentCompleted $event): void
    {
        // À implémenter - envoyer une notification
    }
}
