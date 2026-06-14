<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\EmailVerification;
use App\Models\User;

/**
 * EmailVerificationService
 *
 * Gère la vérification des adresses email
 * Peut être rendu obligatoire pour certaines actions
 *
 * @package App\Modules\Auth\Application\Services
 */
class EmailVerificationService
{
    public function __construct(
        private readonly OTPService $otpService,
    ) {}

    /**
     * Demander la vérification d'un email (création compte ou changement)
     */
    public function requestVerification(User $user, ?string $email = null): array
    {
        $email = $email ?? $user->email;

        // Supprimer les vérifications antérieures non complétées
        EmailVerification::where('user_id', $user->id)
                         ->where('email', $email)
                         ->where('is_verified', false)
                         ->delete();

        // Créer un token de vérification
        $token = bin2hex(random_bytes(32));

        EmailVerification::create([
            'user_id'    => $user->id,
            'email'      => $email,
            'token'      => $token,
            'is_verified' => false,
            'expires_at' => now()->addHours(24),
        ]);

        // Envoyer OTP
        $this->otpService->generateAndSendOTP($user, 'email_verification', $email);

        return [
            'message' => 'Email de vérification envoyé. Veuillez entrer le code OTP.',
            'email'   => $email,
        ];
    }

    /**
     * Vérifier l'email avec OTP
     */
    public function verifyEmailWithOTP(User $user, string $otp, string $email): bool
    {
        $result = $this->otpService->verifyOTP($user, $otp, 'email_verification');

        if (!$result['valid']) {
            return false;
        }

        // Marquer l'email comme vérifié
        $emailVerification = EmailVerification::where('user_id', $user->id)
                                               ->where('email', $email)
                                               ->first();

        if ($emailVerification) {
            $emailVerification->markAsVerified();
        }

        // Mettre à jour le modèle User
        $user->update([
            'email'             => $email,
            'email_verified'    => true,
            'email_verified_at' => now(),
        ]);

        return true;
    }

    /**
     * Vérifier l'email avec token (lien de vérification future)
     */
    public function verifyEmailWithToken(string $token): array
    {
        $emailVerification = EmailVerification::where('token', $token)->first();

        if (!$emailVerification) {
            return [
                'valid'   => false,
                'message' => 'Lien de vérification invalide.',
            ];
        }

        if ($emailVerification->isExpired()) {
            $emailVerification->delete();
            return [
                'valid'   => false,
                'message' => 'Lien de vérification expiré.',
            ];
        }

        if ($emailVerification->is_verified) {
            return [
                'valid'   => false,
                'message' => 'Email déjà vérifié.',
            ];
        }

        // Marquer comme vérifié
        $emailVerification->markAsVerified();

        $user = $emailVerification->user;
        $user->update([
            'email_verified'    => true,
            'email_verified_at' => now(),
        ]);

        return [
            'valid'   => true,
            'message' => 'Email vérifié avec succès.',
        ];
    }

    /**
     * Vérifier si l'email d'un utilisateur est vérifié
     */
    public function isEmailVerified(User $user): bool
    {
        return $user->email_verified;
    }

    /**
     * Supprimer les vérifications expirées (cron job)
     */
    public function cleanupExpiredVerifications(): int
    {
        return EmailVerification::where('expires_at', '<', now())->delete();
    }
}
