<?php

namespace App\Modules\Evenement\Application\Jobs;

use App\Models\Evenement;
use App\Models\EvenementStateTransition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job — Termine automatiquement les événements actifs dont la date de fin
 * est atteinte (actif -> termine). Dès lors, plus aucun paiement n'est accepté.
 *
 * À exécuter via scheduler : Schedule::job(new TerminateExpiredEvenementsJob())->everyMinute();
 */
class TerminateExpiredEvenementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $evenements = Evenement::where('statut', Evenement::STATUT_ACTIF)
            ->whereNotNull('dateFin')
            ->where('dateFin', '<=', now())
            ->get();

        if ($evenements->isEmpty()) {
            return;
        }

        foreach ($evenements as $evenement) {
            DB::transaction(function () use ($evenement) {
                $evenement->update(['statut' => Evenement::STATUT_TERMINE, 'termineAt' => now()]);

                EvenementStateTransition::create([
                    'evenementId'   => $evenement->id,
                    'userId'        => null,
                    'ancienStatut'  => Evenement::STATUT_ACTIF,
                    'nouveauStatut' => Evenement::STATUT_TERMINE,
                    'raison'        => 'date de fin atteinte (automatique)',
                ]);
            });

            Log::info('TerminateExpiredEvenementsJob: événement terminé', ['evenement_id' => $evenement->id]);
        }
    }
}
