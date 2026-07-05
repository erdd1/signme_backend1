<?php

namespace App\Modules\Evenement\Application\Jobs;

use App\Models\Evenement;
use App\Models\EvenementStateTransition;
use App\Modules\Push\Application\Jobs\SendNewContentPushJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job — Publie automatiquement les événements planifiés dont la date/heure
 * de publication programmée est atteinte (planifie -> actif).
 *
 * À exécuter via scheduler : Schedule::job(new PublishScheduledEvenementsJob())->everyMinute();
 */
class PublishScheduledEvenementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $evenements = Evenement::where('statut', Evenement::STATUT_PLANIFIE)
            ->whereNotNull('scheduledPublishAt')
            ->where('scheduledPublishAt', '<=', now())
            ->get();

        if ($evenements->isEmpty()) {
            return;
        }

        foreach ($evenements as $evenement) {
            DB::transaction(function () use ($evenement) {
                $evenement->update(['statut' => Evenement::STATUT_ACTIF, 'publishedAt' => now()]);

                EvenementStateTransition::create([
                    'evenementId'   => $evenement->id,
                    'userId'        => null,
                    'ancienStatut'  => Evenement::STATUT_PLANIFIE,
                    'nouveauStatut' => Evenement::STATUT_ACTIF,
                    'raison'        => 'publication planifiée automatique',
                ]);

                SendNewContentPushJob::dispatch(
                    (int) $evenement->churchId,
                    (int) $evenement->auteurId,
                    'Nouvel événement',
                    $evenement->titre,
                    ['type' => 'evenement', 'id' => $evenement->uuid],
                );
            });

            Log::info('PublishScheduledEvenementsJob: événement publié', ['evenement_id' => $evenement->id]);
        }
    }
}
