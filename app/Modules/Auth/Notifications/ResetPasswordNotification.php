<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Envoi synchrone : pas de worker queue requis pour la réinitialisation.
 */
class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = urlencode($notifiable->getEmailForPasswordReset());
        $deepLink = config('signme.password_reset_deep_link')
            . '?token=' . urlencode($this->token)
            . '&email=' . $email;

        $webUrl = config('signme.password_reset_web_url')
            . '?token=' . urlencode($this->token)
            . '&email=' . $email;

        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe SignMe')
            ->greeting('Bonjour ' . ($notifiable->nom ?? '') . ',')
            ->line('Vous avez demandé la réinitialisation de votre mot de passe SignMe.')
            ->line('**Option 1 — Application mobile** : ouvrez SignMe, allez sur « Nouveau mot de passe » et collez le code ci-dessous.')
            ->line('Code : **' . $this->token . '**')
            ->action('Ouvrir SignMe', $deepLink)
            ->line('Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :')
            ->line($webUrl)
            ->line('Ce lien expire dans 60 minutes.')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.');
    }
}
