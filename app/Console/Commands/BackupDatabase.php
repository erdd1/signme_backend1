<?php

namespace App\Console\Commands;

use App\Core\Services\DatabaseBackupService;
use App\Models\Backup;
use Illuminate\Console\Command;

/**
 * Sauvegarde la base de données (locale + hors-site si configuré) et purge
 * les sauvegardes expirées selon la rétention configurée.
 *
 * Usage : php artisan app:backup-database [--type=scheduled|manual]
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:backup-database {--type=scheduled : scheduled|manual|pre_restore_safety}';

    protected $description = 'Sauvegarde la base de données sur les disques locaux et hors-site';

    public function handle(DatabaseBackupService $service): int
    {
        $type = $this->option('type');

        $this->info("Sauvegarde en cours (type: {$type})...");

        try {
            $backup = $service->createBackup($type);
        } catch (\Throwable $e) {
            $this->error("Échec de la sauvegarde : {$e->getMessage()}");
            return self::FAILURE;
        }

        match ($backup->status) {
            Backup::STATUS_SUCCESS => $this->info("✅ Sauvegarde réussie : {$backup->filename} (" . number_format($backup->size_bytes / 1024 / 1024, 2) . " Mo, disques: " . implode(', ', $backup->disks) . ')'),
            Backup::STATUS_PARTIAL => $this->warn("⚠️  Sauvegarde partielle : {$backup->filename} — copie locale réussie, hors-site échouée ({$backup->error_message})"),
            default => $this->error("Statut inattendu : {$backup->status}"),
        };

        $pruned = $service->pruneOldBackups();
        if ($pruned > 0) {
            $this->line("🧹 {$pruned} ancienne(s) sauvegarde(s) purgée(s) (rétention : " . config('backup.retention_days') . ' jours).');
        }

        return self::SUCCESS;
    }
}
