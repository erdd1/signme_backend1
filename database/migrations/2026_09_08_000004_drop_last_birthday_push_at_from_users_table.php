<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.lastBirthdayPushAt` était le garde-fou anti-doublon de l'ancien
 * SendBirthdayPushesJob (un seul type d'occasion). Le module Célébrations le
 * remplace par la contrainte unique (userId, celebrationTypeId, occursOn) de
 * la table `celebrations`, qui couvre désormais les 9 occasions — colonne
 * devenue redondante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lastBirthdayPushAt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('lastBirthdayPushAt')->nullable()->after('dateNaissance');
        });
    }
};
