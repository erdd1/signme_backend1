<?php

namespace App\Modules\Payment\Application\Jobs;

use App\Models\PaymentSyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job planifié — Purge les lignes payment_sync_logs de plus de 7 jours.
 *
 * `payment_sync_logs` est un diagnostic TECHNIQUE verbeux (chaque ping de
 * polling automatique) — distinct de `payment_events` (historique métier
 * permanent, jamais purgé par ce job).
 *
 * À exécuter via scheduler : Schedule::job(...)->daily();
 */
class PurgeOldPaymentSyncLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $deleted = PaymentSyncLog::where('created_at', '<', now()->subDays(7))->delete();

        Log::info('PurgeOldPaymentSyncLogsJob: purge terminée', ['deleted' => $deleted]);
    }
}
