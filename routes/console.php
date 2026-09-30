<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Core\Services\SystemHealthService;
use App\Jobs\RetireAnciensDeServiceExpires;
use App\Models\Backup;
use App\Modules\Evenement\Application\Jobs\PublishScheduledEvenementsJob;
use App\Modules\Evenement\Application\Jobs\TerminateExpiredEvenementsJob;
use App\Modules\Evenement\Application\Jobs\ArchiveExpiredEvenementsJob;
use App\Modules\Evenement\Application\Jobs\ExpireStaleContributionsJob;
use App\Modules\Payment\Application\Jobs\ExpireStalePaymentTransactionsJob;
use App\Modules\Payment\Application\Jobs\PurgeOldPaymentSyncLogsJob;
use App\Modules\Push\Application\Jobs\SendCelebrationPushesJob;
use App\Modules\System\Application\Jobs\RunDatabaseBackupJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/*
|─────────────────────────────────────────────────────────────────────────────
| Scheduler — Retrait automatique des anciens de service
|─────────────────────────────────────────────────────────────────────────────
|
| Job quotidien qui vérifie et retire les anciens de service dont la période
| de service est expirée (dernier jour du mois de service à 23h59).
|
| À configurer dans le crontab du serveur pour exécuter la commande:
| * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
*/
\Illuminate\Support\Facades\Schedule::job(new RetireAnciensDeServiceExpires())
    ->daily()
    ->at('01:00') // Exécuté chaque jour à 01:00 du matin
    ->name('retire-anciens-de-service-expires')
    ->after(fn () => SystemHealthService::recordHeartbeat('retire-anciens-de-service-expires'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'Job RetireAnciensDeServiceExpires a échoué'
        );
    });

/*
|─────────────────────────────────────────────────────────────────────────────
| Scheduler — Module Evenements (collecte de fonds)
|─────────────────────────────────────────────────────────────────────────────
*/
\Illuminate\Support\Facades\Schedule::job(new PublishScheduledEvenementsJob())
    ->everyMinute()
    ->name('publish-scheduled-evenements')
    ->after(fn () => SystemHealthService::recordHeartbeat('publish-scheduled-evenements'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job PublishScheduledEvenementsJob a échoué');
    });

\Illuminate\Support\Facades\Schedule::job(new TerminateExpiredEvenementsJob())
    ->everyMinute()
    ->name('terminate-expired-evenements')
    ->after(fn () => SystemHealthService::recordHeartbeat('terminate-expired-evenements'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job TerminateExpiredEvenementsJob a échoué');
    });

\Illuminate\Support\Facades\Schedule::job(new ArchiveExpiredEvenementsJob())
    ->hourly()
    ->name('archive-expired-evenements')
    ->after(fn () => SystemHealthService::recordHeartbeat('archive-expired-evenements'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job ArchiveExpiredEvenementsJob a échoué');
    });

/*
|─────────────────────────────────────────────────────────────────────────────
| Scheduler — Expiration des paiements "pending" bloqués (Signature + Evenement)
|─────────────────────────────────────────────────────────────────────────────
| Filet de sécurité si le worker de queue tombe en panne ou si le webhook
| NotchPay n'arrive jamais. Vérifie TOUJOURS le statut auprès de NotchPay
| avant d'expirer quoi que ce soit — un paiement réellement reçu n'est
| jamais perdu.
*/
\Illuminate\Support\Facades\Schedule::job(new ExpireStalePaymentTransactionsJob())
    ->everyFiveMinutes()
    ->name('expire-stale-payment-transactions')
    ->after(fn () => SystemHealthService::recordHeartbeat('expire-stale-payment-transactions'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job ExpireStalePaymentTransactionsJob a échoué');
    });

\Illuminate\Support\Facades\Schedule::job(new ExpireStaleContributionsJob())
    ->everyFiveMinutes()
    ->name('expire-stale-contributions')
    ->after(fn () => SystemHealthService::recordHeartbeat('expire-stale-contributions'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job ExpireStaleContributionsJob a échoué');
    });

/*
|─────────────────────────────────────────────────────────────────────────────
| Scheduler — Purge du diagnostic technique de paiement (payment_sync_logs)
|─────────────────────────────────────────────────────────────────────────────
| Ne touche jamais payment_events (historique métier permanent).
*/
\Illuminate\Support\Facades\Schedule::job(new PurgeOldPaymentSyncLogsJob())
    ->daily()
    ->at('02:00')
    ->name('purge-old-payment-sync-logs')
    ->after(fn () => SystemHealthService::recordHeartbeat('purge-old-payment-sync-logs'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job PurgeOldPaymentSyncLogsJob a échoué');
    });

/*
|─────────────────────────────────────────────────────────────────────────────
| Scheduler — Module Célébrations (anniversaire + 8 occasions calendaires,
| 6h heure locale de chaque utilisateur)
|─────────────────────────────────────────────────────────────────────────────
*/
\Illuminate\Support\Facades\Schedule::job(new SendCelebrationPushesJob())
    ->everyFifteenMinutes()
    ->name('send-celebration-pushes')
    ->after(fn () => SystemHealthService::recordHeartbeat('send-celebration-pushes'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job SendCelebrationPushesJob a échoué');
    });

/*
|─────────────────────────────────────────────────────────────────────────────
| Scheduler — Sauvegarde automatique de la base de données (locale + hors-site)
|─────────────────────────────────────────────────────────────────────────────
*/
\Illuminate\Support\Facades\Schedule::job(new RunDatabaseBackupJob(Backup::TYPE_SCHEDULED))
    ->daily()
    ->at('02:30')
    ->name('backup-database')
    ->after(fn () => SystemHealthService::recordHeartbeat('backup-database'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Job RunDatabaseBackupJob (sauvegarde planifiée) a échoué');
    });

