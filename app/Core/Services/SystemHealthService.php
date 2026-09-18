<?php

namespace App\Core\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * État de santé opérationnel de l'application, consommé par le tableau de
 * bord admin (page "Santé système"). Chaque section est indépendante : une
 * panne Redis ne doit jamais empêcher de savoir que la base de données va
 * bien, par exemple.
 */
class SystemHealthService
{
    public function check(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'disk' => $this->checkDisk(),
            'queue' => $this->checkQueue(),
            'scheduled_jobs' => $this->checkScheduledJobs(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');

            return [
                'status' => 'ok',
                'driver' => DB::connection()->getDriverName(),
                'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::connection()->ping();

            return [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    private function checkDisk(): array
    {
        $path = storage_path();
        $free = disk_free_space($path);
        $total = disk_total_space($path);

        if ($free === false || $total === false || $total === 0) {
            return ['status' => 'unknown'];
        }

        $usedPercent = round((1 - $free / $total) * 100, 1);

        return [
            'status' => $usedPercent >= config('system_health.disk_warning_percent') ? 'warning' : 'ok',
            'free_bytes' => $free,
            'total_bytes' => $total,
            'used_percent' => $usedPercent,
        ];
    }

    private function checkQueue(): array
    {
        try {
            $failedCount = DB::table('failed_jobs')->count();

            return [
                'status' => $failedCount > 0 ? 'warning' : 'ok',
                'failed_jobs' => $failedCount,
            ];
        } catch (Throwable $e) {
            return ['status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    private function checkScheduledJobs(): array
    {
        $jobs = [];

        foreach (config('system_health.scheduled_jobs', []) as $name => $meta) {
            $lastRunAt = Cache::get(self::heartbeatKey($name));
            $status = 'never_run';

            if ($lastRunAt !== null) {
                $minutesAgo = Carbon::parse($lastRunAt)->diffInMinutes(now());
                $status = $minutesAgo <= $meta['max_interval_minutes'] ? 'ok' : 'stale';
            }

            $jobs[$name] = [
                'label' => $meta['label'],
                'status' => $status,
                'last_run_at' => $lastRunAt,
            ];
        }

        return $jobs;
    }

    public static function heartbeatKey(string $jobName): string
    {
        return "schedule_heartbeat:{$jobName}";
    }

    public static function recordHeartbeat(string $jobName): void
    {
        Cache::forever(self::heartbeatKey($jobName), now()->toIso8601String());
    }
}
