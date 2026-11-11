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
use App\Modules\Payment\Application\PaymentService;
use App\Modules\Payment\Domain\PaymentRepositoryInterface;
use App\Modules\Payment\Infrastructure\PaymentRepository;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Payment\Infrastructure\ExternalServices\FlutterwaveGateway;
use App\Modules\Payment\Application\Services\PaymentGatewayRegistry;
use App\Modules\Publication\Application\Services\PublicationService;
use App\Modules\AncienDesignation\Application\Services\AncienDesignationService;
use App\Modules\Rapport\Application\Services\RapportService;
use App\Modules\Evenement\Application\Services\EvenementService;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Evenement\Application\Services\ContributionPollingDispatcher;
use App\Modules\Evenement\Application\Services\MesContributionsService;
use App\Modules\UserLookup\Application\Services\GroupeService;
use App\Modules\UserLookup\Application\Services\QuartierService;
use App\Modules\UserLookup\Application\Services\VilleService;
use App\Modules\Push\Application\Services\DeviceTokenService;
use App\Modules\Push\Application\Services\PushNotificationService;
use App\Modules\AboutChurch\Application\Services\AboutChurchService;
use App\Modules\AboutChurch\Application\Services\AboutChurchArticleService;
use App\Modules\AboutChurch\Application\Services\ChurchAboutInfoService;
use App\Modules\Tresorerie\Application\Services\SortieFinanciereService;
use App\Modules\Retrait\Application\Services\RetraitService;
use App\Modules\Celebration\Application\Services\CelebrationService;
use App\Modules\Celebration\Application\Services\CelebrationTypeService;
use App\Modules\Assistant\Application\Contracts\ChatModel;
use App\Modules\Assistant\Infrastructure\OpenAiCompatibleChatClient;
use App\Modules\Chant\Application\Contracts\LyricsFragmentResolver;
use App\Modules\Chant\Application\Contracts\LyricsProvider;
use App\Modules\Chant\Infrastructure\GeniusFragmentResolver;
use App\Modules\Chant\Infrastructure\LrclibClient;
use App\Core\Services\ImageStorageService;
use App\Core\Services\PaymentQueueHealthService;

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
        // ─── Services communs ─────────────────────────────────────────────────
        $this->app->singleton(ImageStorageService::class);
        $this->app->singleton(PaymentQueueHealthService::class);

        // ─── Module Auth ──────────────────────────────────────────────────────
        $this->app->singleton(\App\Modules\Auth\Application\Services\GoogleTokenVerifier::class);

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
                $app->make(\App\Modules\Auth\Application\Services\GoogleTokenVerifier::class),
            );
        });

        // ─── Services de sécurité (PHASE 1) ────────────────────────────────
        $this->app->singleton(\App\Modules\Auth\Application\Services\RefreshTokenService::class);
        $this->app->singleton(\App\Modules\Auth\Application\Services\RateLimitService::class);
        $this->app->singleton(\App\Modules\Auth\Application\Services\PasswordResetOTPService::class);

        // ─── Module Payment (files Redis NotchPay) ────────────────────────────
        $this->app->singleton(\App\Modules\Payment\Application\Services\PaymentPollingDispatcher::class);
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
                $app->make(ImageStorageService::class)
            );
        });

        // ─── Module Payment (NotchPay) ──────────────────────────────────────────
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->singleton(NotchPayGateway::class, fn () => new NotchPayGateway());
        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService(
                $app->make(PaymentRepositoryInterface::class),
                $app->make(NotchPayGateway::class),
            );
        });
        // ─── Module Signature ─────────────────────────────────────────────────
        $this->app->singleton(SignatureService::class, function ($app) {
            return new SignatureService(
                $app->make(NotchPayGateway::class),
                $app->make(AncienDesignationService::class),
                $app->make(\App\Modules\Payment\Application\Services\PaymentPollingDispatcher::class),
                $app->make(PaymentQueueHealthService::class),
                $app->make(FlutterwaveGateway::class),
                $app->make(PaymentGatewayRegistry::class),
            );
        });

        // ─── Module Publication ───────────────────────────────────────────────
        $this->app->singleton(PublicationService::class);

        // ─── Module AncienDesignation ─────────────────────────────────────────
        $this->app->singleton(AncienDesignationService::class);

        // ─── Module Rapport ───────────────────────────────────────────────────
        $this->app->singleton(RapportService::class);

        // ─── Module UserLookup (listes gérées Groupe/Quartier/Ville) ──────────
        $this->app->singleton(GroupeService::class);
        $this->app->singleton(QuartierService::class);
        $this->app->singleton(VilleService::class);

        // ─── Module Push (notifications FCM) ───────────────────────────────────
        $this->app->singleton(DeviceTokenService::class);
        $this->app->singleton(PushNotificationService::class);

        // ─── Module Evenement (collecte de fonds) ─────────────────────────────
        $this->app->singleton(EvenementService::class, function ($app) {
            return new EvenementService(
                $app->make(ImageStorageService::class),
            );
        });

        $this->app->singleton(ContributionPollingDispatcher::class);

        $this->app->singleton(ContributionService::class, function ($app) {
            return new ContributionService(
                $app->make(NotchPayGateway::class),
                $app->make(ContributionPollingDispatcher::class),
                $app->make(PaymentQueueHealthService::class),
                $app->make(FlutterwaveGateway::class),
                $app->make(PaymentGatewayRegistry::class),
            );
        });

        $this->app->singleton(MesContributionsService::class);

        // ─── Module AboutChurch ("À propos de mon Église") ────────────────────
        $this->app->singleton(ChurchAboutInfoService::class);

        $this->app->singleton(AboutChurchArticleService::class, function ($app) {
            return new AboutChurchArticleService(
                $app->make(ImageStorageService::class),
            );
        });

        $this->app->singleton(AboutChurchService::class, function ($app) {
            return new AboutChurchService(
                $app->make(ChurchAboutInfoService::class),
                $app->make(AboutChurchArticleService::class),
            );
        });

        // ─── Module Tresorerie (sorties financières) ───────────────────────────
        $this->app->singleton(SortieFinanciereService::class);

        // ─── Module Celebration (anniversaires + occasions calendaires) ───────
        $this->app->singleton(CelebrationService::class);
        $this->app->singleton(CelebrationTypeService::class);

        // ─── Module Assistant religieux (moteur au format chat/completions) ───
        $this->app->bind(ChatModel::class, OpenAiCompatibleChatClient::class);
        // ─── Module Chants (paroles via LRCLIB, extraits via Genius) ──────────
        $this->app->bind(LyricsProvider::class, LrclibClient::class);
        $this->app->bind(LyricsFragmentResolver::class, GeniusFragmentResolver::class);

        // ─── Module Retrait (payout NotchPay vers Orange Money/MTN) ───────────
        $this->app->singleton(RetraitService::class, function ($app) {
            return new RetraitService(
                $app->make(SortieFinanciereService::class),
                $app->make(NotchPayGateway::class),
            );
        });
    }

    /**
     * Bootstrap des services
     */
    public function boot(): void
    {
        $this->configureAuthRouteRateLimiting();
        $this->configureAssistantRateLimiting();
        $this->configureChantsRateLimiting();

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
     * Limite de rafale de l'assistant (les quotas quotidiens sont appliqués
     * par AssistantService, qui sait distinguer succès et échecs du moteur).
     */
    protected function configureAssistantRateLimiting(): void
    {
        RateLimiter::for('assistant', function (Request $request) {
            return Limit::perMinute(config('assistant.rate_limit_per_minute'))
                ->by($request->user()?->id ?? $request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'success' => false,
                    'message' => 'Vous posez vos questions un peu vite. Patientez quelques instants.',
                ], 429, $headers));
        });
    }

    /**
     * Limite du répertoire de chants : protège les sources tierces (LRCLIB,
     * Genius) contre les rafales d'un même utilisateur.
     */
    protected function configureChantsRateLimiting(): void
    {
        RateLimiter::for('chants', function (Request $request) {
            return Limit::perMinute(config('chants.rate_limit_per_minute'))
                ->by($request->user()?->id ?? $request->ip());
        });
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
