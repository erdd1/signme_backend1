<?php

namespace App\Console\Commands;

use App\Core\Services\DatabaseBackupService;
use App\Models\Backup;
use Illuminate\Console\Command;

/**
 * Restaure la base de données à partir d'une sauvegarde existante. Une
 * sauvegarde de sécurité ("pre_restore_safety") est TOUJOURS créée avant
 * toute restauration — si elle échoue, la restauration est annulée par
 * prudence plutôt que de risquer une perte de données irréversible.
 *
 * Usage : php artisan app:restore-database {uuid} [--force]
 */
class RestoreDatabase extends Command
{
    protected $signature = 'app:restore-database {uuid : UUID de la sauvegarde à restaurer} {--force : Ignorer la confirmation interactive}';

    protected $description = 'Restaure la base de données à partir d\'une sauvegarde (ATTENTION : opération destructrice)';

    public function handle(DatabaseBackupService $service): int
    {
        $backup = Backup::where('uuid', $this->argument('uuid'))->first();

        if (!$backup) {
            $this->error('Sauvegarde introuvable.');
            return self::FAILURE;
        }

        $this->warn("⚠️  Vous êtes sur le point de REMPLACER la base de données actuelle par la sauvegarde du {$backup->created_at->format('d/m/Y H:i')} ({$backup->filename}).");

        if (!$this->option('force') && !$this->confirm('Cette action est destructrice et irréversible. Confirmer ?', false)) {
            $this->info('Annulé.');
            return self::SUCCESS;
        }

        $this->info("Création d'une sauvegarde de sécurité avant restauration...");
        try {
            $safety = $service->createBackup(Backup::TYPE_PRE_RESTORE_SAFETY);
            $this->info("✅ Sauvegarde de sécurité créée : {$safety->filename}");
        } catch (\Throwable $e) {
            $this->error("Échec de la sauvegarde de sécurité — restauration ANNULÉE par prudence : {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info('Restauration en cours...');
        try {
            $service->restoreBackup($backup);
        } catch (\Throwable $e) {
            $this->error("Échec de la restauration : {$e->getMessage()}");
            $this->warn("La sauvegarde de sécurité {$safety->filename} reste disponible pour revenir à l'état précédent.");
            return self::FAILURE;
        }

        $this->info('✅ Restauration terminée avec succès.');
        return self::SUCCESS;
    }
}
