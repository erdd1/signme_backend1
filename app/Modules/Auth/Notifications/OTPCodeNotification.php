<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification OTP générique — vérification email et activation MFA.
 * (La réinitialisation de mot de passe a sa propre notification dédiée,
 * PasswordResetOTPNotification, avec un flux de renvoi/escalade distinct.)
 */
class OTPCodeNotification extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly string $type,
        private readonly string $userName = 'Utilisateur',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $intro] = match ($this->type) {
            'email_verification' => [
                'Vérification de votre adresse email - EEC SignCard',
                'Vous avez demandé à vérifier votre adresse email SignMe.',
            ],
            'mfa' => [
                'Code d\'authentification - EEC SignCard',
                'Vous avez demandé à activer la double authentification sur SignMe.',
            ],
            default => [
                'Code de sécurité - EEC SignCard',
                'Voici votre code de sécurité SignMe.',
            ],
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Bonjour ' . $this->userName . ',')
            ->line($intro)
            ->line('')
            ->line('Votre code de vérification (valide 10 minutes) :')
            ->line('')
            ->line('**' . $this->code . '**')
            ->line('')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.')
            ->line('Ce code n\'est valable qu\'une seule fois.');
    }
}
