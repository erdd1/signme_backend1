<?php

namespace App\Modules\System\Http;

use App\Core\Exceptions\ApiException;
use App\Core\Services\LogViewerService;
use App\Core\Services\SystemHealthService;
use App\Core\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Modules\System\Application\Jobs\RunDatabaseBackupJob;
use App\Modules\System\Application\Jobs\RunDatabaseRestoreJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * SystemController — Maintenance de production (ADMIN ONLY)
 *
 * GET  /api/admin/system/health              → Santé système (DB, Redis, disque, queue, tâches planifiées)
 * GET  /api/admin/system/backups             → Historique des sauvegardes
 * POST /api/admin/system/backups             → Déclenche une sauvegarde manuelle (asynchrone)
 * GET  /api/admin/system/backups/{uuid}/download → Télécharge le fichier de sauvegarde
 * POST /api/admin/system/backups/{uuid}/restore  → Restaure la base de données (asynchrone, destructeur)
 * GET  /api/admin/system/maintenance         → Statut du mode maintenance
 * POST /api/admin/system/maintenance/enable  → Active le mode maintenance
 * POST /api/admin/system/maintenance/disable → Désactive le mode maintenance
 * GET  /api/admin/system/logs                → Dernières entrées du log applicatif (filtrable)
 * GET  /api/admin/system/logs/download        → Télécharge le fichier de log brut du jour
 */
class SystemController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly SystemHealthService $healthService,
        private readonly LogViewerService $logViewerService,
    ) {
    }

    public function health(): JsonResponse
    {
        return $this->successResponse($this->healthService->check());
    }

    public function listBackups(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $backups = Backup::with('triggeredBy:id,nom')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($backups);
    }

    public function triggerBackup(Request $request): JsonResponse
    {
        // Le job crée lui-même sa ligne Backup (via DatabaseBackupService)
        // une fois qu'il démarre réellement — elle apparaîtra dans la liste
        // avec le statut "pending" puis "success"/"failed".
        RunDatabaseBackupJob::dispatch(Backup::TYPE_MANUAL, $request->user()->id);

        return $this->successResponse(
            null,
            'Sauvegarde lancée en arrière-plan — elle apparaîtra dans la liste une fois terminée.',
            202
        );
    }

    public function downloadBackup(string $uuid): mixed
    {
        $backup = Backup::where('uuid', $uuid)->firstOrFail();

        $localDisk = Storage::disk(config('backup.local_disk'));
        if ($localDisk->exists($backup->filename)) {
            return $localDisk->download($backup->filename);
        }

        $offsiteDisk = Storage::disk(config('backup.offsite_disk'));
        if (in_array(config('backup.offsite_disk'), $backup->disks ?? [], true) && $offsiteDisk->exists($backup->filename)) {
            return $offsiteDisk->download($backup->filename);
        }

        throw ApiException::notFound('Fichier de sauvegarde');
    }

    public function restoreBackup(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'accepted'],
        ]);

        $backup = Backup::where('uuid', $uuid)->firstOrFail();

        if (!in_array($backup->status, [Backup::STATUS_SUCCESS, Backup::STATUS_PARTIAL], true)) {
            throw ApiException::conflict("Impossible de restaurer une sauvegarde au statut '{$backup->status}'.");
        }

        RunDatabaseRestoreJob::dispatch($backup->id);

        return $this->successResponse(
            null,
            'Restauration lancée en arrière-plan. Une sauvegarde de sécurité sera créée automatiquement avant toute modification.',
            202
        );
    }

    public function maintenanceStatus(): JsonResponse
    {
        return $this->successResponse([
            'active' => app()->isDownForMaintenance(),
        ]);
    }

    public function enableMaintenance(Request $request): JsonResponse
    {
        $request->validate([
            'retry' => ['nullable', 'integer', 'min:1'],
        ]);

        // L'API admin reste joignable pendant la maintenance (exceptée dans
        // bootstrap/app.php) — sans ça, l'administrateur qui active le mode
        // maintenance perdrait aussitôt tout moyen de le désactiver depuis
        // le dashboard lui-même. Pas d'option "--message" : absente de cette
        // version d'Artisan down — les clients API reçoivent de toute façon
        // une réponse JSON 503 générique, pas une page HTML personnalisée.
        Artisan::call('down', array_filter([
            '--retry' => $request->integer('retry') ?: null,
        ]));

        return $this->successResponse(['active' => true], 'Mode maintenance activé.');
    }

    public function disableMaintenance(): JsonResponse
    {
        Artisan::call('up');

        return $this->successResponse(['active' => false], 'Mode maintenance désactivé.');
    }

    public function logs(Request $request): JsonResponse
    {
        $request->validate([
            'level' => ['nullable', 'string', 'in:' . implode(',', $this->logViewerService->availableLevels())],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $entries = $this->logViewerService->tail(
            limit: $request->integer('limit', 200),
            level: $request->string('level')->value() ?: null,
            search: $request->string('search')->value() ?: null,
        );

        return $this->successResponse([
            'entries' => $entries,
            'levels' => $this->logViewerService->availableLevels(),
        ]);
    }

    public function downloadLog(): mixed
    {
        $path = $this->logViewerService->currentLogPath();

        if (!is_file($path)) {
            throw ApiException::notFound('Fichier de log');
        }

        return response()->download($path, basename($path));
    }
}
