<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table celebrations — Instances générées (une par utilisateur,
 * par type, par occurrence) par le job planifié. Alimente la liste "Mes
 * célébrations" (lu/non-lu) et sert de cible au deep-link du push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('celebrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('userId');
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            // Snapshot — permet à l'admin de filtrer par église sans jointure,
            // stable même si l'utilisateur change d'église plus tard.
            $table->unsignedBigInteger('churchId');
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            $table->foreignId('celebrationTypeId')->constrained('celebration_types')->cascadeOnDelete();

            $table->date('occursOn');

            $table->boolean('isRead')->default(false);
            $table->timestamp('readAt')->nullable();

            $table->timestamps();

            // Un seul envoi par utilisateur/type/date — garde-fou anti-doublon
            // (remplace users.lastBirthdayPushAt, désormais redondant).
            $table->unique(['userId', 'celebrationTypeId', 'occursOn'], 'uq_user_type_occurrence');

            $table->index(['userId', 'isRead'], 'idx_user_unread');
            $table->index(['churchId', 'occursOn'], 'idx_church_occurs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('celebrations');
    }
};
