<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table ancien_designations
 *
 * Table de jonction : quels anciens sont désignés de service pour quel mois/église.
 * UNIQUE (ancienId, churchId, mois, annee) — un ancien ne peut être désigné qu'une fois par mois/église.
 * Inclut les champs de disponibilité (jours/horaires) définis par le pasteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ancien_designations', function (Blueprint $table) {
            $table->id();

            // ─── L'ancien désigné ─────────────────────────────────────────────
            $table->unsignedBigInteger('ancienId')->index();
            $table->foreign('ancienId')->references('id')->on('users')->cascadeOnDelete();
            $table->string('ancienNom', 255)->nullable(); // Dénormalisé

            // ─── L'église concernée ───────────────────────────────────────────
            $table->unsignedBigInteger('egliseId')->index();
            $table->foreign('egliseId')->references('id')->on('churches')->cascadeOnDelete();

            // ─── La période ───────────────────────────────────────────────────
            $table->tinyInteger('mois');    // 1 à 12
            $table->smallInteger('annee');

            // ─── Qui a fait la désignation ? ──────────────────────────────────
            $table->unsignedBigInteger('designePar')->nullable();
            $table->foreign('designePar')->references('id')->on('users')->nullOnDelete();
            $table->string('designeParNom', 255)->nullable(); // Dénormalisé

            // ─── Disponibilité de l'ancien de service ─────────────────────────
            // Jours de travail (JSON array: [1,2,3,4,5,6,7] = lun-dim)
            // NULL = disponible 24/7 tous les jours
            $table->json('jours_travail')
                  ->nullable()
                  ->comment('Days available: JSON array [1-7] for Mon-Sun, or null for 24/7');

            // Horaire début (HH:MM) — null = 00:00
            $table->time('horaire_debut')
                  ->nullable()
                  ->comment('Start time (HH:MM), null for 00:00');

            // Horaire fin (HH:MM) — null = 23:59
            $table->time('horaire_fin')
                  ->nullable()
                  ->comment('End time (HH:MM), null for 23:59');

            // Date de fin de service (pour suppression automatique = dernier jour du mois)
            $table->dateTime('date_fin_service')
                  ->nullable()
                  ->comment('Auto-remove ancien from service at this datetime');

            $table->timestamps();

            // ─── Contraintes ──────────────────────────────────────────────────
            $table->unique(
                ['ancienId', 'egliseId', 'mois', 'annee'],
                'uq_ancien_church_period'
            );

            $table->index(['egliseId', 'mois', 'annee'], 'idx_church_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ancien_designations');
    }
};
