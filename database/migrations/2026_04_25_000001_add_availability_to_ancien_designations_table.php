<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Ajouter les champs de disponibilité aux anciennes désignations
 *
 * Permet au pasteur de définir les jours et horaires de travail pour un ancien
 * de service désigné pour un mois donné.
 *
 * - jours_travail: JSON array [1,2,3,4,5,6,7] (lundi-dimanche) ou NULL (24/7)
 * - horaire_debut: TIME ou NULL (00:00 par défaut)
 * - horaire_fin: TIME ou NULL (23:59 par défaut)
 * - date_fin_service: DATE pour suppression automatique le dernier jour du mois
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ancien_designations', function (Blueprint $table) {
            // Jours de travail (JSON array: [1,2,3,4,5,6,7] = lun-dim)
            // NULL = disponible 24/7 tous les jours
            $table->json('jours_travail')
                  ->nullable()
                  ->after('designeParNom')
                  ->comment('Days available: JSON array [1-7] for Mon-Sun, or null for 24/7');

            // Horaire début (HH:MM)
            $table->time('horaire_debut')
                  ->nullable()
                  ->after('jours_travail')
                  ->comment('Start time (HH:MM), null for 00:00');

            // Horaire fin (HH:MM)
            $table->time('horaire_fin')
                  ->nullable()
                  ->after('horaire_debut')
                  ->comment('End time (HH:MM), null for 23:59');

            // Date de fin de service (pour suppression automatique)
            // = dernier jour du mois à 23h59
            $table->dateTime('date_fin_service')
                  ->nullable()
                  ->after('horaire_fin')
                  ->comment('Auto-remove ancien from service at this datetime');
        });
    }

    public function down(): void
    {
        Schema::table('ancien_designations', function (Blueprint $table) {
            $table->dropColumn(['jours_travail', 'horaire_debut', 'horaire_fin', 'date_fin_service']);
        });
    }
};
