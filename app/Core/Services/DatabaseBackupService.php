<?php

namespace App\Core\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Sauvegarde/restauration de la base de données applicative.
 *
 * Écrit toujours d'abord sur le disque local "backups-local" (restauration
 * rapide, aucune dépendance réseau) puis tente une copie hors-site sur le
 * disque "backups" (S3-compatible) — la survie des données ne doit pas
 * dépendre uniquement du disque du serveur applicatif. Si les identifiants
 * hors-site ne sont pas encore configurés (ex: avant l'hébergement Contabo),
 * la sauvegarde reste "success" mais hors-site n'est pas alimenté — un
 * avertissement est journalisé pour rester visible sans jamais faire
 * échouer une sauvegarde par ailleurs valide.
 */
class DatabaseBackupService
{
    public function createBackup(string $type = Backup::TYPE_SCHEDULED, ?int $triggeredBy = null): Backup
    {
        $driver = DB::connection()->getDriverName();
        $extension = $driver === 'pgsql' ? 'dump' : 'sql.gz';
        $filename = sprintf('signme_%s_%s.%s', $driver, now()->format('Y-m-d_H-i-s'), $extension);

        $backup = Backup::create([
            'filename' => $filename,
            'type' => $type,
            'status' => Backup::STATUS_PENDING,
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        try {
            $localDisk = Storage::disk(config('backup.local_disk'));
            $localPath = $localDisk->path($filename);

            $driver === 'pgsql'
                ? $this->dumpPgsql($localPath)
                : $this->dumpMysql($localPath);

            $sizeBytes = filesize($localPath);
            $disksWritten = [config('backup.local_disk')];

            $offsiteError = null;
            if ($this->offsiteConfigured()) {
                try {
                    $this->uploadOffsite($filename, $localPath);
                    $disksWritten[] = config('backup.offsite_disk');
                } catch (\Throwable $e) {
                    $offsiteError = $e->getMessage();
                    Log::error('Sauvegarde hors-site échouée (copie locale OK)', [
                        'filename' => $filename,
                        'error' => $offsiteError,
                    ]);
                }
            } else {
                Log::warning('Sauvegarde créée sans copie hors-site : identifiants BACKUP_S3_* non configurés', [
                    'filename' => $filename,
                ]);
            }

            $backup->update([
                'status' => $offsiteError ? Backup::STATUS_PARTIAL : Backup::STATUS_SUCCESS,
                'disks' => $disksWritten,
                'size_bytes' => $sizeBytes,
                'error_message' => $offsiteError,
                'completed_at' => now(),
            ]);

            return $backup->fresh();
        } catch (\Throwable $e) {
            Log::error('Sauvegarde de la base de données échouée', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            $backup->update([
                'status' => Backup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function offsiteConfigured(): bool
    {
        $disk = config('filesystems.disks.' . config('backup.offsite_disk'));

        return filled($disk['key'] ?? null)
            && filled($disk['secret'] ?? null)
            && filled($disk['bucket'] ?? null);
    }

    /**
     * Supprime les sauvegardes (fichiers + enregistrements) plus vieilles que
     * la rétention configurée, sur les deux disques. Une erreur hors-site
     * n'empêche pas le nettoyage local, et vice-versa — chaque disque est
     * indépendant.
     *
     * @return int Nombre de sauvegardes purgées
     */
    public function pruneOldBackups(): int
    {
        $cutoff = now()->subDays((int) config('backup.retention_days'));
        $expired = Backup::successful()->where('created_at', '<', $cutoff)->get();

        $pruned = 0;
        foreach ($expired as $backup) {
            foreach ($backup->disks ?? [] as $disk) {
                try {
                    Storage::disk($disk)->delete($backup->filename);
                } catch (\Throwable $e) {
                    Log::warning('Échec de la suppression d\'une sauvegarde expirée', [
                        'filename' => $backup->filename,
                        'disk' => $disk,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $backup->delete();
            $pruned++;
        }

        return $pruned;
    }

    /**
     * Restaure la base de données à partir d'une sauvegarde existante.
     * Opération DESTRUCTRICE — l'appelant (commande/contrôleur) est
     * responsable de la confirmation et de la sauvegarde de sécurité
     * préalable, ce service se contente d'exécuter la restauration.
     */
    public function restoreBackup(Backup $backup): void
    {
        if (!in_array($backup->status, [Backup::STATUS_SUCCESS, Backup::STATUS_PARTIAL], true)) {
            throw new \RuntimeException("Impossible de restaurer une sauvegarde au statut '{$backup->status}'.");
        }

        $driver = DB::connection()->getDriverName();
        $filePath = $this->resolveLocalFile($backup);

        try {
            $driver === 'pgsql'
                ? $this->restorePgsql($filePath)
                : $this->restoreMysql($filePath);
        } finally {
            $this->cleanupTempFile($filePath, $backup->filename);
        }
    }

    /**
     * Renvoie un chemin local vers le fichier de sauvegarde, en le
     * téléchargeant depuis le disque hors-site dans un dossier temporaire
     * s'il n'est plus présent localement (ex: purgé par la rétention mais
     * toujours disponible hors-site).
     */
    private function resolveLocalFile(Backup $backup): string
    {
        $localDisk = Storage::disk(config('backup.local_disk'));
        if ($localDisk->exists($backup->filename)) {
            return $localDisk->path($backup->filename);
        }

        if (!in_array(config('backup.offsite_disk'), $backup->disks ?? [], true)) {
            throw new \RuntimeException("Fichier de sauvegarde introuvable, ni localement ni hors-site : {$backup->filename}");
        }

        $tmpDir = storage_path('app/backups-restore-tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . $backup->filename;

        $stream = Storage::disk(config('backup.offsite_disk'))->readStream($backup->filename);
        if ($stream === null) {
            throw new \RuntimeException("Téléchargement hors-site impossible : {$backup->filename}");
        }

        file_put_contents($tmpPath, stream_get_contents($stream));
        fclose($stream);

        return $tmpPath;
    }

    private function cleanupTempFile(string $filePath, string $filename): void
    {
        // Ne supprimer que le fichier temporaire de restauration téléchargé
        // depuis le hors-site — jamais le fichier officiel du disque local.
        if (str_contains($filePath, 'backups-restore-tmp')) {
            @unlink($filePath);
        }
    }

    private function restorePgsql(string $filePath): void
    {
        $config = DB::connection()->getConfig();

        $result = Process::env(['PGPASSWORD' => $config['password'] ?? ''])
            ->timeout(config('backup.process_timeout'))
            ->run([
                config('backup.pgsql.pg_restore'),
                '-h', $config['host'],
                '-p', (string) $config['port'],
                '-U', $config['username'],
                '--clean', '--if-exists', '--no-owner',
                '-d', $config['database'],
                $filePath,
            ]);

        if (!$result->successful()) {
            throw new \RuntimeException('pg_restore a échoué : ' . $result->errorOutput());
        }
    }

    private function restoreMysql(string $filePath): void
    {
        $config = DB::connection()->getConfig();
        $sql = gzdecode(file_get_contents($filePath));

        $result = Process::env(['MYSQL_PWD' => $config['password'] ?? ''])
            ->timeout(config('backup.process_timeout'))
            ->input($sql)
            ->run([
                config('backup.mysql.mysql'),
                '-h', $config['host'],
                '-P', (string) $config['port'],
                '-u', $config['username'],
                $config['database'],
            ]);

        if (!$result->successful()) {
            throw new \RuntimeException('Restauration MySQL échouée : ' . $result->errorOutput());
        }
    }

    private function dumpPgsql(string $outputPath): void
    {
        $config = DB::connection()->getConfig();

        $result = Process::env(['PGPASSWORD' => $config['password'] ?? ''])
            ->timeout(config('backup.process_timeout'))
            ->run([
                config('backup.pgsql.pg_dump'),
                '-h', $config['host'],
                '-p', (string) $config['port'],
                '-U', $config['username'],
                '-F', 'c',
                '-f', $outputPath,
                $config['database'],
            ]);

        if (!$result->successful()) {
            throw new \RuntimeException('pg_dump a échoué : ' . $result->errorOutput());
        }
    }

    private function dumpMysql(string $outputPath): void
    {
        $config = DB::connection()->getConfig();

        $result = Process::env(['MYSQL_PWD' => $config['password'] ?? ''])
            ->timeout(config('backup.process_timeout'))
            ->run([
                config('backup.mysql.mysqldump'),
                '-h', $config['host'],
                '-P', (string) $config['port'],
                '-u', $config['username'],
                '--single-transaction',
                '--quick',
                $config['database'],
            ]);

        if (!$result->successful()) {
            throw new \RuntimeException('mysqldump a échoué : ' . $result->errorOutput());
        }

        file_put_contents($outputPath, gzencode($result->output(), 9));
    }

    private function uploadOffsite(string $filename, string $localPath): void
    {
        $stream = fopen($localPath, 'r');

        try {
            Storage::disk(config('backup.offsite_disk'))->put($filename, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
