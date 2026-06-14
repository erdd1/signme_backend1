<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Response;

// ─── Modules — Interfaces & Implémentations ───────────────────────────────────
use App\Modules\Auth\Application\Services\AuthService;
use App\Modules\Church\Application\Services\ChurchService;
use App\Modules\User\Application\Services\UserService;
use App\Modules\Signature\Application\Services\SignatureService;
use App\Modules\Payment\Application\Services\CampayService;
use App\Modules\Payment\Application\PaymentService;
use App\Modules\Payment\Domain\PaymentRepositoryInterface;
use App\Modules\Payment\Infrastructure\PaymentRepository;
use App\Modules\Payment\Infrastructure\ExternalServices\CampayGateway;
use App\Modules\Publication\Application\Services\PublicationService;
use App\Modules\AncienDesignation\Application\Services\AncienDesignationService;
use App\Modules\Rapport\Application\Services\RapportService;
use App\Core\Services\CloudinaryService;

/**
 * AppServiceProvider — EEC SignCard
 *
 * Enregistre tous les services applicatifs dans le conteneur IoC de Laravel.
 * Respecte le principe d'inversion de dépendance (DDD).
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Enregistrement des services (bindings DI)
     */
    public function register(): void
    {
        // Cloudinary Laravel lit filesystems.disks.cloudinary (absent) — on force config/cloudinary.php
        $this->app->singleton(\Cloudinary\Cloudinary::class, function ($app) {
            $cloudUrl = $app['config']->get('cloudinary.cloud_url');
            if (empty($cloudUrl)) {
                throw new \InvalidArgumentException(
                    'Cloudinary non configuré : définissez CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET et CLOUDINARY_CLOUD_NAME dans .env'
                );
            }

            return new \Cloudinary\Cloudinary($cloudUrl);
        });

        // ─── Services communs ─────────────────────────────────────────────────
        $this->app->singleton(CloudinaryService::class);

        // ─── Module Auth ──────────────────────────────────────────────────────
        $this->app->singleton(AuthService::class, function ($app) {
            return new AuthService(
                $app->make(\App\Modules\Auth\Application\Services\RefreshTokenService::class),
                $app->make(\App\Modules\Auth\Application\Services\RateLimitService::class),
                $app->make(\App\Modules\Auth\Application\Services\SecurityLogService::class),
                $app->make(\App\Modules\Auth\Application\Services\MFAService::class),
                $app->make(\App\Modules\Auth\Application\Services\DeviceManagementService::class),
                $app->make(\App\Modules\Auth\Application\Services\SuspiciousActivityDetectionService::class),
                $app->make(\App\Modules\Auth\Application\Services\SecurityNotificationService::class),
                $app->make(\App\Modules\Auth\Application\Services\PasswordResetOTPService::class),
            );
        });

        // ─── Services de sécurité (PHASE 1) ────────────────────────────────
        $this->app->singleton(\App\Modules\Auth\Application\Services\RefreshTokenService::class);
        $this->app->singleton(\App\Modules\Auth\Application\Services\RateLimitService::class);
        $this->app->singleton(\App\Modules\Auth\Application\Services\PasswordResetOTPService::class);

        // ─── Module Payment (files Redis Campay) ─────────────────────────────
        $this->app->singleton(\App\Modules\Payment\Application\Services\PaymentPollingDispatcher::class);
        $this->app->singleton(\App\Modules\Payment\Infrastructure\ExternalServices\CampayGateway::class);
        $this->app->singleton(\App\Modules\Auth\Application\Services\SecurityLogService::class);

        // ─── Services de sécurité (PHASE 2) ────────────────────────────────
        $this->app->singleton(\App\Modules\Auth\Application\Services\OTPService::class);
        
        $this->app->singleton(\App\Modules\Auth\Application\Services\MFAService::class, function ($app) {
            return new \App\Modules\Auth\Application\Services\MFAService(
                $app->make(\App\Modules\Auth\Application\Services\OTPService::class),
                $app->make(\App\Modules\Auth\Application\Services\SecurityLogService::class),
            );
        });

        $this->app->singleton(\App\Modules\Auth\Application\Services\DeviceManagementService::class);
        $this->app->singleton(\App\Modules\Auth\Application\Services\SuspiciousActivityDetectionService::class);
        
        $this->app->singleton(\App\Modules\Auth\Application\Services\EmailVerificationService::class, function ($app) {
            return new \App\Modules\Auth\Application\Services\EmailVerificationService(
                $app->make(\App\Modules\Auth\Application\Services\OTPService::class),
            );
        });

        // ─── Services de sécurité (PHASE 3) ────────────────────────────────
        $this->app->singleton(\App\Modules\Auth\Application\Services\SecurityNotificationService::class);
        $this->app->singleton(\App\Modules\Auth\Application\Services\AuditLogService::class);

        // ─── Module Church ────────────────────────────────────────────────────
        $this->app->singleton(ChurchService::class);

        // ─── Module User ──────────────────────────────────────────────────────
        $this->app->singleton(UserService::class, function ($app) {
            return new UserService(
                $app->make(CloudinaryService::class)
            );
        });

        // ─── Module Payment (Campay) ──────────────────────────────────────────
        $this->app->singleton(CampayService::class);
        // ─── PaymentService avec DDD (pour les nouveaux endpoints) ─────────────
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->singleton(CampayGateway::class, fn () => new CampayGateway());
        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService(
                $app->make(PaymentRepositoryInterface::class),
                $app->make(CampayGateway::class),
            );
        });
        // ─── Module Signature ─────────────────────────────────────────────────
        $this->app->singleton(SignatureService::class, function ($app) {
            return new SignatureService(
                $app->make(CampayGateway::class),
                $app->make(AncienDesignationService::class),
                $app->make(\App\Modules\Payment\Application\Services\PaymentPollingDispatcher::class),
            );
        });

        // ─── Module Publication ───────────────────────────────────────────────
        $this->app->singleton(PublicationService::class);

        // ─── Module AncienDesignation ─────────────────────────────────────────
        $this->app->singleton(AncienDesignationService::class);

        // ─── Module Rapport ───────────────────────────────────────────────────
        $this->app->singleton(RapportService::class);
    }

    /**
     * Bootstrap des services
     */
    public function boot(): void
    {
        $this->configureAuthRouteRateLimiting();

        // ─── Macro de réponse API globale ─────────────────────────────────────
        Response::macro('api', function ($data = null, $message = 'Succès', $code = 200) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => $data,
            ], $code);
        });

        // ─── Observers (activés quand les modèles existent) ───────────────────
        // \App\Models\Signature::observe(\App\Observers\SignatureObserver::class);
        // \App\Models\AncienDesignation::observe(\App\Observers\AncienDesignationObserver::class);
    }

    /**
     * Limites HTTP par endpoint auth (couche réseau, complète le RateLimitService).
     */
    protected function configureAuthRouteRateLimiting(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perMinute(8)->by($email !== '' ? $email : $request->ip()),
            ];
        });

        RateLimiter::for('auth-forgot', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(3)->by(strtolower((string) $request->input('email', $request->ip()))),
            ];
        });

        RateLimiter::for('auth-verify-otp', function (Request $request) {
            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perMinute(5)->by(strtolower((string) $request->input('email', $request->ip()))),
            ];
        });

        RateLimiter::for('auth-resend-otp', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(3)->by(strtolower((string) $request->input('email', $request->ip()))),
            ];
        });

        RateLimiter::for('auth-reset', function (Request $request) {
            return [
                Limit::perMinute(15)->by($request->ip()),
                Limit::perMinute(5)->by(strtolower((string) $request->input('email', $request->ip()))),
            ];
        });

        RateLimiter::for('auth-refresh', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
