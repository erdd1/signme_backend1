<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * SecurityNotificationService
 *
 * Envoie des alertes de sécurité aux utilisateurs par email
 * PHASE 3
 *
 * @package App\Modules\Auth\Application\Services
 */
class SecurityNotificationService
{
    /**
     * Notifier d'une nouvelle connexion
     */
    public function notifyNewLogin(
        User $user,
        string $ipAddress,
        ?string $deviceName = null,
        ?string $location = null
    ): bool {
        if (!$user->security_notifications_enabled) {
            return true; // Silent skip si notifications désactivées
        }

        try {
            $subject = 'Nouvelle connexion à votre compte - EEC SignCard';
            
            $message = "Bonjour {$user->nom},\n\n"
                . "Une nouvelle connexion à votre compte a été détectée:\n\n"
                . "📍 Adresse IP: $ipAddress\n"
                . "⏰ Heure: " . now()->format('d/m/Y H:i:s') . "\n";
            
            if ($deviceName) {
                $message .= "📱 Appareil: $deviceName\n";
            }
            
            if ($location) {
                $message .= "🌍 Localisation: $location\n";
            }

            $message .= "\n⚠️  Si ce n'est pas vous, contactez immédiatement votre pasteur ou l'administrateur.\n"
                . "Lien d'accès: " . config('app.url') . "/security/devices\n\n"
                . "Cordialement,\nL'équipe EEC SignCard";

            $this->sendEmail($user->email, $subject, $message);

            Log::info("Notification connexion envoyée", [
                'user_id' => $user->id,
                'ip'      => $ipAddress,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Erreur notification connexion: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifier d'un changement de mot de passe
     */
    public function notifyPasswordChange(User $user): bool
    {
        if (!$user->security_notifications_enabled) {
            return true;
        }

        try {
            $subject = 'Votre mot de passe a été modifié - EEC SignCard';
            
            $message = "Bonjour {$user->nom},\n\n"
                . "Votre mot de passe a été modifié avec succès.\n\n"
                . "⏰ Heure: " . now()->format('d/m/Y H:i:s') . "\n\n"
                . "⚠️  Si vous n'avez pas effectué cette action, contactez immédiatement votre pasteur.\n\n"
                . "Cordialement,\nL'équipe EEC SignCard";

            $this->sendEmail($user->email, $subject, $message);

            Log::info("Notification changement password envoyée", ['user_id' => $user->id]);

            return true;
        } catch (\Exception $e) {
            Log::error("Erreur notification password change: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifier d'un nouvel appareil connecté
     */
    public function notifyNewDevice(
        User $user,
        string $deviceName,
        string $ipAddress,
        ?string $location = null
    ): bool {
        if (!$user->security_notifications_enabled) {
            return true;
        }

        try {
            $subject = 'Nouvel appareil connecté à votre compte - EEC SignCard';
            
            $message = "Bonjour {$user->nom},\n\n"
                . "Un nouvel appareil s'est connecté à votre compte:\n\n"
                . "📱 Appareil: $deviceName\n"
                . "📍 Adresse IP: $ipAddress\n"
                . "⏰ Heure: " . now()->format('d/m/Y H:i:s') . "\n";

            if ($location) {
                $message .= "🌍 Localisation: $location\n";
            }

            $message .= "\n👉 Allez à votre page de sécurité pour approuver ou rejeter cet appareil:\n"
                . config('app.url') . "/security/devices\n\n"
                . "Cordialement,\nL'équipe EEC SignCard";

            $this->sendEmail($user->email, $subject, $message);

            Log::info("Notification nouvel appareil envoyée", [
                'user_id' => $user->id,
                'device'  => $deviceName,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Erreur notification nouvel appareil: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifier d'une activité suspecte
     */
    public function notifySuspiciousActivity(
        User $user,
        string $activityType,
        string $riskLevel,
        ?string $ipAddress = null,
        ?string $location = null,
        ?array $details = null
    ): bool {
        if (!$user->security_notifications_enabled) {
            return true;
        }

        try {
            $riskEmoji = match($riskLevel) {
                'critical' => '🚨',
                'high'     => '🔴',
                'medium'   => '⚠️',
                'low'      => '⚠️',
                default    => 'ℹ️',
            };

            $activityLabel = match($activityType) {
                'multiple_ips'       => 'Multiples adresses IP détectées',
                'different_countries' => 'Connexion depuis un nouveau pays',
                'brute_force'        => 'Tentatives de connexion suspectes',
                'unusual_time'       => 'Connexion à une heure inhabituelle',
                default              => 'Activité suspecte détectée',
            };

            $subject = "$riskEmoji Alerte de sécurité - EEC SignCard";
            
            $message = "Bonjour {$user->nom},\n\n"
                . "$riskEmoji ALERTE DE SÉCURITÉ\n"
                . "Niveau de risque: $riskLevel\n\n"
                . "📋 Activité: $activityLabel\n";

            if ($ipAddress) {
                $message .= "📍 Adresse IP: $ipAddress\n";
            }

            if ($location) {
                $message .= "🌍 Localisation: $location\n";
            }

            $message .= "⏰ Heure: " . now()->format('d/m/Y H:i:s') . "\n\n"
                . "👉 Consultez vos alertes de sécurité: " . config('app.url') . "/security/alerts\n\n"
                . "Si ce n'est pas vous, changez immédiatement votre mot de passe!\n\n"
                . "Cordialement,\nL'équipe EEC SignCard";

            $this->sendEmail($user->email, $subject, $message);

            Log::info("Notification activité suspecte envoyée", [
                'user_id'     => $user->id,
                'activity'    => $activityType,
                'risk_level'  => $riskLevel,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Erreur notification activité suspecte: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifier d'une tentative MFA échouée
     */
    public function notifyMFAAttempt(User $user, bool $success, ?string $ipAddress = null): bool
    {
        if (!$user->security_notifications_enabled) {
            return true;
        }

        try {
            if ($success) {
                $subject = '✅ Authentification à deux facteurs réussie';
                $message = "Bonjour {$user->nom},\n\n"
                    . "✅ Une authentification à deux facteurs a été validée avec succès.\n\n"
                    . "⏰ Heure: " . now()->format('d/m/Y H:i:s') . "\n";
            } else {
                $subject = '❌ Tentative MFA échouée - Alerte de sécurité';
                $message = "Bonjour {$user->nom},\n\n"
                    . "❌ PLUSIEURS TENTATIVES MFA ÉCHOUÉES DÉTECTÉES\n\n"
                    . "Quelqu'un essaie d'accéder à votre compte!\n\n"
                    . "⏰ Heure: " . now()->format('d/m/Y H:i:s') . "\n";
                
                if ($ipAddress) {
                    $message .= "📍 Adresse IP: $ipAddress\n";
                }

                $message .= "\n👉 Si ce n'est pas vous, changez votre mot de passe immédiatement!\n";
            }

            $message .= "\nCordialement,\nL'équipe EEC SignCard";

            $this->sendEmail($user->email, $subject, $message);

            return true;
        } catch (\Exception $e) {
            Log::error("Erreur notification MFA: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envoyer email générique (stub — intégrer SendGrid, Mailgun, etc.)
     */
    private function sendEmail(string $to, string $subject, string $message): void
    {
        // TODO: Intégrer vrai système d'email
        // Pour maintenant, loguer juste
        Log::info("Email de sécurité envoyé", [
            'to'      => $to,
            'subject' => $subject,
        ]);
    }

    /**
     * Activer/désactiver les notifications de sécurité
     */
    public function updateNotificationPreferences(User $user, bool $enabled): void
    {
        $user->update(['security_notifications_enabled' => $enabled]);

        Log::info("Préférences notifications mises à jour", [
            'user_id' => $user->id,
            'enabled' => $enabled,
        ]);
    }
}
