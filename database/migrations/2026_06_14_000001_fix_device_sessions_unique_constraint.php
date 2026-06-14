<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ⚠️ FIX: Contrainte unique incorrecte
     * 
     * AVANT: device_id était unique tout seul (problème car plusieurs utilisateurs
     *        peuvent avoir device_id=null, causant "Duplicate Entry")
     *
     * APRÈS: Contrainte composite (user_id + device_id) - chaque utilisateur peut
     *        avoir plusieurs appareils, mais pas le même appareil 2 fois
     */
    public function up(): void
    {
        Schema::table('device_sessions', function (Blueprint $table) {
            // Supprimer l'ancienne contrainte unique de device_id seul
            $table->dropUnique('device_sessions_device_id_unique');

            // Ajouter une contrainte composite (user_id + device_id) si device_id n'est pas null
            // Permet plusieurs device_id=null (pour les sessions sans ID)
            $table->unique(['user_id', 'device_id'], 'device_sessions_user_device_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_sessions', function (Blueprint $table) {
            // Restaurer l'ancienne configuration
            $table->dropUnique('device_sessions_user_device_unique');
            $table->unique('device_id');
        });
    }
};
