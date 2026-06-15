<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table device_sessions — Gestion des appareils connectés
 *
 * Contrainte unique composite (user_id + device_id) :
 * chaque utilisateur peut avoir plusieurs appareils, mais pas le même 2 fois.
 * device_id seul n'est pas unique (plusieurs users peuvent avoir device_id=null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('device_id')->nullable();         // Identifiant unique de l'appareil
            $table->string('device_name')->nullable();       // iPhone 12, Samsung Galaxy, etc
            $table->string('device_type')->nullable();       // mobile|web|tablet
            $table->string('os')->nullable();                // iOS|Android|Windows|MacOS
            $table->string('os_version')->nullable();        // 14.5, 12, etc
            $table->string('app_version')->nullable();       // Version de l'app Flutter
            $table->string('browser')->nullable();           // Chrome, Safari, etc (si web)
            $table->ipAddress('ip_address');
            $table->string('country')->nullable();           // Géolocalisation (ex: CM)
            $table->string('city')->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            // ─── Contrainte unique composite ──────────────────────────────────
            // Permet plusieurs device_id=null (sessions sans ID), mais pas le même
            // device_id pour le même utilisateur
            $table->unique(['user_id', 'device_id'], 'device_sessions_user_device_unique');

            // ─── Index pour requêtes fréquentes ──────────────────────────────
            $table->index(['user_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
