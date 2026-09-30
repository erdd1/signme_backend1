<?php

namespace App\Modules\Retrait\Application\Jobs;

use App\Models\Retrait;
use App\Models\RetraitEvent;
use App\Modules\Retrait\Application\Services\RetraitEventRecorder;
use App\Modules\Retrait\Application\Services\RetraitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Traite le webhook NotchPay `transfer.*` en file (réponse HTTP immédiate) —
 * mirroir de ProcessPaymentWebhookJob côté paiements entrants.
 */
class ProcessRetraitWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public array $payload,
    ) {
        $this->onConnection(config('payment.connection', 'redis'));
        $this->onQueue('retraits');
    }

    public function handle(RetraitService $retraitService): void
    {
        $reference = $this->payload['reference'] ?? null;
        if (!$reference) {
            return;
        }

        $retrait = Retrait::where('reference', $reference)->first();
        if (!$retrait) {
            Log::warning('ProcessRetraitWebhookJob: retrait introuvable', ['reference' => $reference]);
            return;
        }

        // La signature a déjà été validée par PaymentController avant la mise en file.
        $retraitService->applyWebhookStatus($retrait, $this->payload);

        RetraitEventRecorder::recordEvent(
            $retrait->id,
            RetraitEvent::TYPE_WEBHOOK,
            $retrait->fresh()->statut,
            $this->payload,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessRetraitWebhookJob échoué', [
            'reference' => $this->payload['reference'] ?? null,
            'error'     => $exception->getMessage(),
        ]);
    }
}
