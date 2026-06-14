<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Class PaymentNotification
 * 
 * Notification pour les événements de paiement
 */
class PaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $payment;
    protected $message;

    public function __construct($payment, $message = '')
    {
        $this->payment = $payment;
        $this->message = $message;
    }

    public function via($notifiable): array
    {
        return ['mail']; // À adapter selon vos besoins (SMS, push, etc.)
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment Notification')
            ->line($this->message)
            ->action('View Payment', url('/'))
            ->line('Thank you for using our application!');
    }
}
