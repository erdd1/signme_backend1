<?php

namespace App\Modules\Evenement\Application\Jobs;

use App\Modules\Evenement\Application\Services\ContributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Traite le webhook NotchPay en file Redis pour une contribution événement.
 *
 * Miroir de App\Modules\Payment\Application\Jobs\ProcessPaymentWebhookJob : la
 * signature a déjà été validée par PaymentController avant la mise en file
 * (aucune duplication de la vérification webhook ni du gateway NotchPay).
 */
class ProcessEvenementContributionWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public array $payload,
    ) {
        $this->onConnection(config('payment.connection', 'redis'));
        $this->onQueue(config('payment.queue', 'payments'));
    }

    public function handle(ContributionService $contributionService): void
    {
        $contributionService->processPaymentNotification($this->payload);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessEvenementContributionWebhookJob échoué', [
            'reference' => $this->payload['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}
