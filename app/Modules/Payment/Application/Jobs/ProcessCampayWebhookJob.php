<?php

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Signature\Application\Services\SignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Traite le webhook Campay en file Redis (réponse HTTP immédiate).
 */
class ProcessCampayWebhookJob implements ShouldQueue
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

    public function handle(SignatureService $signatureService): void
    {
        // La signature a déjà été validée par PaymentController avant la mise en file.
        $signatureService->processCampayNotification($this->payload);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessCampayWebhookJob échoué', [
            'reference' => $this->payload['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}
