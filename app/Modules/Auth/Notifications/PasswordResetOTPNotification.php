<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification OTP pour réinitialisation mot de passe
 * Envoie un code à 6 chiffres à usage unique
 */
class PasswordResetOTPNotification extends Notification
{
    public function __construct(
        private readonly string $otp,
        private readonly string $userName = 'Utilisateur'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Code de réinitialisation SignMe - ' . $this->otp)
            ->greeting('Bonjour ' . $this->userName . ',')
            ->line('Vous avez demandé la réinitialisation de votre mot de passe SignMe.')
            ->line('')
            ->line('Votre code de vérification (valide 10 minutes) :')
            ->line('')
            ->line('**' . $this->otp . '**')
            ->line('')
            ->line('Entrez ce code dans l\'application SignMe pour continuer.')
            ->line('')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.')
            ->line('Ce code n\'est valable qu\'une seule fois.');
    }
}
