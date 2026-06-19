<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Models\OTPCode;
use App\Core\Exceptions\ApiException;
use App\Modules\Auth\Notifications\PasswordResetOTPNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Service de gestion OTP pour réinitialisation mot de passe
 *
 * Responsabilités:
 * - Génération OTP (6 chiffres)
 * - Envoi email
 * - Validation OTP
 * - Gestion escalade renvois (1min → 2min → 5min)
 * - Gestion tentatives (max 5)
 * - Token temporaire de reset après validation
 */
class PasswordResetOTPService
{
    // Configuration
    private const OTP_LENGTH = 6;
    private const OTP_EXPIRES_IN_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const MAX_RESENDS = 3;
    
    // Délais renvoi (en minutes)
    private const RESEND_DELAYS = [1, 2, 5];

    public function __construct(
        private readonly RateLimitService $rateLimitService,
        private readonly SecurityLogService $securityLogService,
    ) {}

    /**
     * Génère et envoie un OTP à 6 chiffres
     *
     * @param User $user
     * @param string $email
     * @return bool
     * @throws ApiException
     */
    public function generateAndSendOTP(User $user, string $email): bool
    {
        $ip = request()->ip();
        
        // ─── Rate limiting par email + IP ────────────────────────────
        $this->rateLimitService->ensureWithinLimit('password_reset_otp', strtolower($email));
        $this->rateLimitService->ensureWithinLimit('password_reset_otp', 'ip:' . $ip);

        // ─── Invalider ancien OTP s'il existe ────────────────────────
        OTPCode::where('user_id', $user->id)
                ->where('type', 'password_reset')
                ->where('is_used', false)
                ->update(['is_used' => true]);

        // ─── Générer OTP 6 chiffres ──────────────────────────────────
        $otp = $this->generateOTP();

        // ─── Sauvegarder en BD ────────────────────────────────────────
        OTPCode::create([
            'user_id'    => $user->id,
            'code'       => $otp,
            'type'       => 'password_reset',
            'email'      => $email,
            'attempts'   => 0,
            'is_used'    => false,
            'expires_at' => Carbon::now()->addMinutes(self::OTP_EXPIRES_IN_MINUTES),
        ]);

        // ─── Envoyer email ────────────────────────────────────────────
        try {
            Notification::send($user, new PasswordResetOTPNotification($otp, $user->nom));
        } catch (\Exception $e) {
            $this->securityLogService->log(
                action: 'password_reset_otp_email_failed',
                status: 'failed',
                details: ['email' => $email, 'reason' => $e->getMessage()]
            );
            throw ApiException::badRequest('Impossible d\'envoyer l\'email. Réessayez plus tard.');
        }

        $this->rateLimitService->incrementRateLimit('password_reset_otp', strtolower($email));
        $this->rateLimitService->incrementRateLimit('password_reset_otp', 'ip:' . $ip);

        return true;
    }

    /**
     * Vérifie un OTP et retourne un token temporaire de reset
     *
     * @param User $user
     * @param string $email
     * @param string $otp
     * @return string Token temporaire (JWT ou UUID pour reset password)
     * @throws ApiException
     */
    public function verifyOTP(User $user, string $email, string $otp): string
    {
        $ip = request()->ip();

        // ─── Rate limiting ────────────────────────────────────────────
        $this->rateLimitService->ensureWithinLimit('password_reset_verify', strtolower($email));

        // ─── Chercher OTP valide ─────────────────────────────────────
        $otpRecord = OTPCode::where('user_id', $user->id)
                            ->where('type', 'password_reset')
                            ->where('email', $email)
                            ->where('is_used', false)
                            ->where('expires_at', '>', Carbon::now())
                            ->first();

        if (!$otpRecord) {
            $this->securityLogService->log(
                action: 'password_reset_otp_not_found',
                status: 'failed',
                user: $user,
                details: ['reason' => 'otp_expired_or_used']
            );
            throw ApiException::badRequest('Code expiré. Demandez un nouveau code.');
        }

        // ─── Vérifier tentatives ─────────────────────────────────────
        if ($otpRecord->attempts >= self::MAX_ATTEMPTS) {
            $otpRecord->update(['is_used' => true]);
            $this->securityLogService->log(
                action: 'password_reset_otp_max_attempts',
                status: 'failed',
                user: $user,
                details: ['attempts' => $otpRecord->attempts]
            );
            throw ApiException::badRequest('Trop de tentatives. Demandez un nouveau code.');
        }

        // ─── Incrémenter tentatives ──────────────────────────────────
        $otpRecord->increment('attempts');

        // ─── Vérifier code ───────────────────────────────────────────
        if ($otpRecord->code !== $otp) {
            $this->rateLimitService->incrementRateLimit('password_reset_verify', strtolower($email));
            $this->securityLogService->log(
                action: 'password_reset_otp_invalid',
                status: 'failed',
                user: $user,
                details: ['attempts' => $otpRecord->attempts]
            );
            throw ApiException::badRequest('Code incorrect. Veuillez réessayer.');
        }

        // ─── Marquer OTP comme validé ────────────────────────────────
        $otpRecord->update([
            'is_used'  => true,
            'used_at'  => Carbon::now(),
        ]);

        // ─── Générer token temporaire de reset (UUID court-lived) ────
        $resetToken = Str::random(64); // Token sécurisé pour reset final

        // Stocker le token avec TTL court (15 min) en cache/BD
        // On va l'ajouter directement au modèle pour simplifier
        cache()->put('password_reset_' . $user->id, [
            'token'  => $resetToken,
            'email'  => $email,
            'otp_id' => $otpRecord->id,
        ], now()->addMinutes(15));

        $this->securityLogService->logPasswordReset($user, false, 'otp_verified');

        return $resetToken;
    }

    /**
     * Envoie un nouveau OTP après vérification des délais
     *
     * @param User $user
     * @param string $email
     * @return array ['next_resend_in_seconds' => ...]
     * @throws ApiException
     */
    public function resendOTP(User $user, string $email): array
    {
        // ─── Chercher dernier OTP ────────────────────────────────────
        $latestOTP = OTPCode::where('user_id', $user->id)
                            ->where('type', 'password_reset')
                            ->where('email', $email)
                            ->latest('created_at')
                            ->first();

        if (!$latestOTP) {
            throw ApiException::badRequest('Aucun OTP en cours. Demandez un nouveau code.');
        }

        // ─── Compter les renvois de la tentative EN COURS ──────────────
        // Fenêtre glissante (et non "depuis minuit") : sinon les renvois
        // s'accumulent sur toute la journée à travers des tentatives de
        // réinitialisation totalement séparées, et le quota se retrouve
        // épuisé avant même que le délai d'attente (1/2/5 min) ne soit
        // respecté. La fenêtre couvre largement l'escalade complète
        // (1 + 2 + 5 = 8 min) avec une marge confortable.
        $resendCount = OTPCode::where('user_id', $user->id)
                              ->where('type', 'password_reset')
                              ->where('email', $email)
                              ->where('created_at', '>=', Carbon::now()->subMinutes(30))
                              ->count();

        if ($resendCount > self::MAX_RESENDS) {
            throw ApiException::tooManyRequests(
                'Nombre maximum de renvois atteint. Veuillez réessayer plus tard.',
                ['retry_after' => 3600] // 1h
            );
        }

        // ─── Vérifier délai d'attente (escalade: 1, 2, 5 min) ─────────
        $delayMinutes = self::RESEND_DELAYS[$resendCount - 1] ?? 5;
        $nextAllowedAt = $latestOTP->created_at->addMinutes($delayMinutes);

        if (Carbon::now()->isBefore($nextAllowedAt)) {
            $remainingSeconds = $nextAllowedAt->diffInSeconds(Carbon::now());
            throw ApiException::tooManyRequests(
                'Veuillez attendre avant de demander un nouveau code.',
                ['retry_after' => $remainingSeconds]
            );
        }

        // ─── Générer et envoyer nouvel OTP ────────────────────────────
        $this->generateAndSendOTP($user, $email);

        // Délai avant le PROCHAIN renvoi possible (palier suivant de
        // l'escalade) — un nouvel OTP vient d'être créé, donc le prochain
        // appel comptera $resendCount + 1.
        $nextDelayMinutes = self::RESEND_DELAYS[$resendCount] ?? 5;

        return [
            'success'               => true,
            'message'               => 'Code renvoyé avec succès.',
            'next_resend_in_seconds' => $nextDelayMinutes * 60,
        ];
    }

    /**
     * Génère un OTP aléatoire de 6 chiffres
     */
    private function generateOTP(): string
    {
        return str_pad(random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }
}
