<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Empêche l'envoi en double de la notification d'anniversaire
 * (le job de balayage tourne toutes les 15 minutes ; sans ce garde-fou, un
 * utilisateur recevrait plusieurs "Joyeux anniversaire" dans la même matinée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('lastBirthdayPushAt')->nullable()->after('dateNaissance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lastBirthdayPushAt');
        });
    }
};
