<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Email vérifié - DÉJÀ EXISTANT (ne pas dupliquer)
            // Ajouter seulement la colonne email_verified (bool) car email_verified_at existe déjà
            
            if (!Schema::hasColumn('users', 'email_verified')) {
                $table->boolean('email_verified')->default(false)->after('email');
            }

            // Réception notifications de sécurité
            if (!Schema::hasColumn('users', 'security_notifications_enabled')) {
                $table->boolean('security_notifications_enabled')->default(true)->after('email_verified');
            }

            // Dernier login
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('security_notifications_enabled');
            }

            // Compteur tentatives échouées (cache rapide sans table séparée)
            if (!Schema::hasColumn('users', 'failed_login_attempts')) {
                $table->integer('failed_login_attempts')->default(0)->after('last_login_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumnIfExists([
                'email_verified',
                'security_notifications_enabled',
                'last_login_at',
                'failed_login_attempts',
            ]);
        });
    }
};
