<?php

namespace App\Modules\Evenement\Application\Jobs;

use App\Modules\Evenement\Application\Services\EvenementService;

/**
 * Job — Archive automatiquement les événements terminés depuis plus de
 * Evenement::JOURS_AVANT_ARCHIVAGE jours (termine -> archive).
 *
 * Le compte à rebours ("Archivage dans J-3/J-2/J-1") est calculé à la volée
 * dans EvenementResource, jamais stocké — ce job n'écrit que la transition finale.
 *
 * Délègue à EvenementService::archiveOverdueEvenements(), qui est aussi
 * appelée paresseusement depuis list()/listForGestion() — ce job planifié
 * n'est donc qu'un filet de sécurité redondant, pas l'unique déclencheur
 * (voir le commentaire sur cette méthode pour le contexte).
 *
 * Pas de ShouldQueue : cette tâche est légère, rapide et idempotente — la
 * mettre en file exigerait un worker de queue en plus du scheduler pour
 * s'exécuter, une dépendance supplémentaire inutile ici.
 *
 * À exécuter via scheduler : Schedule::job(new ArchiveExpiredEvenementsJob())->hourly();
 */
class ArchiveExpiredEvenementsJob
{
    public function handle(EvenementService $evenementService): void
    {
        $evenementService->archiveOverdueEvenements();
    }
}
