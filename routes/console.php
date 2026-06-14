<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\RetireAnciensDeServiceExpires;

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
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'Job RetireAnciensDeServiceExpires a échoué'
        );
    });

