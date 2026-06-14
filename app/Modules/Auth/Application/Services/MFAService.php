<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\MFACredential;
use App\Models\User;

/**
 * MFAService
 *
 * Gère l'authentification multi-facteur (MFA)
 * Supporte: OTP email, TOTP (future)
 *
 * @package App\Modules\Auth\Application\Services
 */
class MFAService
{
    public function __construct(
        private readonly OTPService $otpService,
        private readonly SecurityLogService $securityLogService,
    ) {}

    /**
     * Vérifier si l'utilisateur a MFA activé
     */
    public function isMFAEnabled(User $user): bool
    {
        $mfa = $user->mfaCredential ?? MFACredential::firstOrCreate(
            ['user_id' => $user->id],
            ['mfa_enabled' => false, 'mfa_type' => 'email']
        );

        return $mfa->isEnabled();
    }

    /**
     * Activer MFA (via email pour Phase 2)
     */
    public function enableMFA(User $user, string $type = 'email'): array
    {
        $mfa = MFACredential::firstOrCreate(['user_id' => $user->id]);

        // Générer codes de secours
        $backupCodes = MFACredential::generateBackupCodes();

        $mfa->update([
            'mfa_type'     => $type,
            'backup_codes' => $backupCodes,
            // NE PAS activer directement, attendre vérification
        ]);

        // Envoyer OTP de vérification
        $this->otpService->generateAndSendOTP($user, 'mfa');

        $this->securityLogService->log(
            action: 'mfa_setup_initiated',
            status: 'success',
            user: $user,
            details: ['mfa_type' => $type]
        );

        return [
            'backup_codes' => $backupCodes,
            'message'      => 'OTP envoyé à votre email. Vérifiez votre email pour confirmer.',
        ];
    }

    /**
     * Confirmer l'activation MFA avec OTP
     */
    public function confirmMFASetup(User $user, string $otp): bool
    {
        $result = $this->otpService->verifyOTP($user, $otp, 'mfa');

        if (!$result['valid']) {
            $this->securityLogService->log(
                action: 'mfa_verification_failed',
                status: 'failed',
                user: $user,
                details: ['reason' => 'invalid_otp']
            );
            return false;
        }

        // Activer MFA maintenant
        $mfa = MFACredential::where('user_id', $user->id)->first();
        $mfa->enable();

        $this->securityLogService->log(
            action: 'mfa_enabled',
            status: 'success',
            user: $user,
            details: ['mfa_type' => $mfa->mfa_type]
        );

        return true;
    }

    /**
     * Désactiver MFA
     */
    public function disableMFA(User $user): void
    {
        $mfa = MFACredential::where('user_id', $user->id)->first();
        if ($mfa) {
            $mfa->disable();
        }

        $this->securityLogService->log(
            action: 'mfa_disabled',
            status: 'success',
            user: $user
        );
    }

    /**
     * Vérifier la 2e authentification (OTP ou code de secours)
     */
    public function verifySecondFactor(User $user, string $code): bool
    {
        $mfa = MFACredential::where('user_id', $user->id)->first();

        if (!$mfa || !$mfa->isEnabled()) {
            return true; // MFA non activé = pas de 2e facteur
        }

        // Essayer le code OTP d'abord
        $result = $this->otpService->verifyOTP($user, $code, 'mfa');
        if ($result['valid']) {
            return true;
        }

        // Essayer les codes de secours
        if ($mfa->useBackupCode($code)) {
            $this->securityLogService->log(
                action: 'mfa_backup_code_used',
                status: 'success',
                user: $user
            );
            return true;
        }

        return false;
    }

    /**
     * Obtenir les credentials MFA pour un utilisateur
     */
    public function getMFAStatus(User $user): array
    {
        $mfa = MFACredential::where('user_id', $user->id)->first();

        return [
            'enabled'        => $mfa?->isEnabled() ?? false,
            'type'          => $mfa?->mfa_type ?? null,
            'backup_codes_remaining' => count($mfa?->backup_codes ?? []),
        ];
    }
}
