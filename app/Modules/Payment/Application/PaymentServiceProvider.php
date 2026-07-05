<?php

namespace App\Modules\Payment\Application;

use App\Modules\Payment\Domain\PaymentRepositoryInterface;
use App\Modules\Payment\Infrastructure\PaymentRepository;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Class PaymentServiceProvider
 *
 * Service Provider pour la configuration du module Payment
 * Enregistre les bindings et la configuration
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Enregistrer le repository dans le conteneur DI
        $this->app->bind(
            PaymentRepositoryInterface::class,
            PaymentRepository::class
        );

        // Enregistrer NotchPay Gateway comme singleton
        $this->app->singleton(NotchPayGateway::class, function () {
            return new NotchPayGateway();
        });

        // Enregistrer PaymentService
        $this->app->bind(PaymentService::class, function ($app) {
            return new PaymentService(
                $app->make(PaymentRepositoryInterface::class),
                $app->make(NotchPayGateway::class),
            );
        });
    }

    public function boot(): void
    {
        $this->mergeConfigFrom(base_path('config/payment.php'), 'payment');
    }
}
