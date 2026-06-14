<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table ancien_designations
 *
 * Table de jonction : quels anciens sont désignés de service pour quel mois/église.
 * UNIQUE (ancienId, churchId, mois, annee) — un ancien ne peut être désigné qu'une fois par mois/église.
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
