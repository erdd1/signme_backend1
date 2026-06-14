<?php

namespace App\Jobs;

use App\Models\AncienDesignation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job — RetireAnciensDeServiceExpires
 *
 * Supprime automatiquement les anciennes désignations qui sont arrivées à expiration
 * (dernier jour du mois de service à 23h59).
 *
 * À exécuter via scheduler: $schedule->job(new RetireAnciensDeServiceExpires())->daily();
 */
class RetireAnciensDeServiceExpires implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        // Récupère les désignations expirées (date_fin_service < maintenant)
        $expirees = AncienDesignation::where('date_fin_service', '<=', now())
                                      ->get();

        if ($expirees->isEmpty()) {
            Log::info('RetireAnciensDeServiceExpires: Aucune désignation expirée à traiter.');
            return;
        }

        Log::info("RetireAnciensDeServiceExpires: {$expirees->count()} désignation(s) à retirer.");

        foreach ($expirees as $designation) {
            $this->retireDesignation($designation);
        }

        Log::info("RetireAnciensDeServiceExpires: Nettoyage complété.");
    }

    private function retireDesignation(AncienDesignation $designation): void
    {
        try {
            $ancienId = $designation->ancienId;

            // Supprimer la désignation
            $designation->delete();

            // Vérifier s'il reste d'autres désignations actives pour cet ancien
            // (peut être désigné dans plusieurs contextes)
            $autresDesignations = AncienDesignation::where('ancienId', $ancienId)
                                                    ->where('date_fin_service', '>', now())
                                                    ->exists();

            if (!$autresDesignations) {
                // Plus de désignation active → retirer le flag est_de_service
                User::where('id', $ancienId)
                     ->update(['est_de_service' => false]);

                Log::info(
                    "RetireAnciensDeServiceExpires: Ancien #{$ancienId} retiré du service.",
                    ['ancien_id' => $ancienId]
                );
            }
        } catch (\Exception $e) {
            Log::error(
                'RetireAnciensDeServiceExpires: Erreur lors du retrait',
                [
                    'ancien_id' => $designation->ancienId,
                    'designation_id' => $designation->id,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }
}
