<?php

namespace App\Modules\System\Application\Jobs;

use App\Core\Services\DatabaseBackupService;
use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Restaure la base de données en tâche de fond. Crée systématiquement une
 * sauvegarde de sécurité juste avant — si celle-ci échoue, l'exception
 * remonte AVANT tout appel à restoreBackup(), annulant la restauration par
 * prudence plutôt que de risquer une perte de données irréversible.
 */
class RunDatabaseRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(private readonly int $backupId)
    {
    }

    public function handle(DatabaseBackupService $service): void
    {
        $backup = Backup::findOrFail($this->backupId);

        $safety = $service->createBackup(Backup::TYPE_PRE_RESTORE_SAFETY);
        Log::warning('RunDatabaseRestoreJob : sauvegarde de sécurité créée avant restauration', [
            'safety_backup' => $safety->filename,
            'target_backup' => $backup->filename,
        ]);

        $service->restoreBackup($backup);
        Log::warning('RunDatabaseRestoreJob : restauration terminée', ['backup' => $backup->filename]);
    }
}
