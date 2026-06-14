<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\OTPCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * OTPService
 *
 * Gère la génération, validation et envoi des codes OTP (One-Time Passwords)
 * Utilisés pour: vérification email, MFA, reset password
 *
 * @package App\Modules\Auth\Application\Services
 */
class OTPService
{
    private int $otpExpiryMinutes = 10;
    private int $maxAttempts = 5;

    /**
     * Générer et envoyer un OTP par email
     *
     * @param User $user
     * @param string $type (email_verification|mfa|password_reset)
     * @param string|null $email
     * @return bool
     */
    public function generateAndSendOTP(
        User $user,
        string $type = 'mfa',
        ?string $email = null
    ): bool {
        $email = $email ?? $user->email;

        // Supprimer les OTP non utilisés précédents
        OTPCode::where('user_id', $user->id)
                ->where('type', $type)
                ->where('is_used', false)
                ->delete();

        // Générer le code
        $code = OTPCode::generateCode();

        // Créer l'enregistrement
        $otp = OTPCode::create([
            'user_id'   => $user->id,
            'code'      => $code,
            'type'      => $type,
            'email'     => $email,
            'attempts'  => 0,
            'is_used'   => false,
            'expires_at' => now()->addMinutes($this->otpExpiryMinutes),
        ]);

        // Envoyer par email
        return $this->sendOTPEmail($user, $email, $code, $type);
    }

    /**
     * Vérifier un code OTP
     *
     * @param User $user
     * @param string $code
     * @param string $type
     * @return array ['valid' => bool, 'message' => string]
     */
    public function verifyOTP(User $user, string $code, string $type = 'mfa'): array
    {
        // Trouver le code OTP
        $otp = OTPCode::where('user_id', $user->id)
                       ->where('type', $type)
                       ->where('code', $code)
                       ->first();

        if (!$otp) {
            return [
                'valid'   => false,
                'message' => 'Code OTP invalide.',
            ];
        }

        // Vérifier expiration
        if ($otp->isExpired()) {
            $otp->delete();
            return [
                'valid'   => false,
                'message' => 'Code OTP expiré. Veuillez en demander un nouveau.',
            ];
        }

        // Vérifier utilisation
        if ($otp->isUsed()) {
            return [
                'valid'   => false,
                'message' => 'Code OTP déjà utilisé.',
            ];
        }

        // Incrémenter tentatives
        $otp->incrementAttempts();

        // Vérifier si trop de tentatives
        if ($otp->attempts > $this->maxAttempts) {
            $otp->delete();
            return [
                'valid'   => false,
                'message' => 'Trop de tentatives. Code annulé.',
            ];
        }

        // Code valide !
        $otp->markAsUsed();

        return [
            'valid'   => true,
            'message' => 'Code OTP vérifié avec succès.',
        ];
    }

    /**
     * Envoyer le code OTP par email
     */
    private function sendOTPEmail(
        User $user,
        string $email,
        string $code,
        string $type
    ): bool {
        try {
            $subject = match($type) {
                'email_verification' => 'Vérification de votre adresse email - EEC SignCard',
                'mfa'                => 'Code d\'authentification - EEC SignCard',
                'password_reset'     => 'Code de réinitialisation - EEC SignCard',
                default              => 'Code de sécurité - EEC SignCard',
            };

            $message = match($type) {
                'email_verification' => "Utilisez ce code pour vérifier votre email: $code\n\nCode valide pendant 10 minutes.",
                'mfa'                => "Utilisez ce code pour activer la double authentification: $code\n\nCode valide pendant 10 minutes.",
                'password_reset'     => "Utilisez ce code pour réinitialiser votre mot de passe: $code\n\nCode valide pendant 10 minutes.",
                default              => "Votre code de sécurité: $code\n\nCode valide pendant 10 minutes.",
            };

            // TODO: Intégrer vrai système d'email (SendGrid, Mailgun, etc)
            // Pour maintenant, loguer juste
            \Log::info("OTP Email envoyé", [
                'user_id' => $user->id,
                'email'   => $email,
                'type'    => $type,
                'code'    => $code,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error("Erreur envoi OTP: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer les OTP expirés (cron job)
     */
    public function cleanupExpiredOTPs(): int
    {
        return OTPCode::where('expires_at', '<', now())->delete();
    }
}
