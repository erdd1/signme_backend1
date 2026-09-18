<?php

namespace App\Modules\System\Application\Jobs;

use App\Core\Services\DatabaseBackupService;
use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Exécute une sauvegarde de la base de données en tâche de fond — un dump
 * peut prendre plusieurs dizaines de secondes une fois la base réellement
 * volumineuse, ce qui dépasserait le délai d'une requête HTTP synchrone
 * (déclenchement manuel depuis l'admin) ou du scheduler.
 *
 * Aucun try/catch ici : DatabaseBackupService::createBackup() enregistre déjà
 * l'échec sur le modèle Backup avant de relancer l'exception — la laisser
 * remonter permet au ->onFailure() du scheduler et au tracking failed_jobs
 * de la queue de l'observer aussi (défense en profondeur).
 */
class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        private readonly string $type = Backup::TYPE_MANUAL,
        private readonly ?int $triggeredBy = null,
    ) {
    }

    public function handle(DatabaseBackupService $service): void
    {
        $service->createBackup($this->type, $this->triggeredBy);
    }
}
