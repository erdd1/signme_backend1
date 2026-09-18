<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tâches planifiées surveillées
    |--------------------------------------------------------------------------
    |
    | Miroir des noms donnés via ->name(...) dans routes/console.php. Chaque
    | tâche journalise son heure de dernière exécution réussie (cache, clé
    | "schedule_heartbeat:<nom>") — max_interval_minutes doit rester
    | confortablement au-dessus de la fréquence réelle de la tâche pour
    | absorber les petits retards sans fausse alerte.
    |
    */

    'scheduled_jobs' => [
        'retire-anciens-de-service-expires' => ['label' => 'Retrait des anciens de service expirés', 'max_interval_minutes' => 26 * 60],
        'publish-scheduled-evenements' => ['label' => 'Publication des événements planifiés', 'max_interval_minutes' => 10],
        'terminate-expired-evenements' => ['label' => 'Clôture des événements expirés', 'max_interval_minutes' => 10],
        'archive-expired-evenements' => ['label' => 'Archivage des événements expirés', 'max_interval_minutes' => 90],
        'expire-stale-payment-transactions' => ['label' => 'Expiration des paiements bloqués (signatures)', 'max_interval_minutes' => 15],
        'expire-stale-contributions' => ['label' => 'Expiration des contributions bloquées', 'max_interval_minutes' => 15],
        'purge-old-payment-sync-logs' => ['label' => 'Purge des logs techniques de paiement', 'max_interval_minutes' => 26 * 60],
        'send-birthday-pushes' => ['label' => "Envoi des notifications d'anniversaire", 'max_interval_minutes' => 30],
        'backup-database' => ['label' => 'Sauvegarde automatique de la base de données', 'max_interval_minutes' => 26 * 60],
    ],

    // Seuil d'alerte (pourcentage d'espace disque utilisé sur le volume de storage/).
    'disk_warning_percent' => (int) env('SYSTEM_HEALTH_DISK_WARNING_PERCENT', 90),

];
