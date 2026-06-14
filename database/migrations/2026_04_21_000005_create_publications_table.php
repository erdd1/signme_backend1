<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table publications — Annonces et méditations de l'église
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();

            // ─── Appartenance ─────────────────────────────────────────────────
            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            // ─── Type de publication ──────────────────────────────────────────
            $table->enum('type', ['annonce', 'meditation']);

            // ─── Contenu ──────────────────────────────────────────────────────
            $table->string('titre', 255);
            $table->text('contenu');
            // Uniquement pour les méditations
            $table->string('referenceBiblique', 100)->nullable();

            // ─── Auteur (dénormalisé pour perf) ──────────────────────────────
            $table->unsignedBigInteger('auteurId')->nullable();
            $table->foreign('auteurId')->references('id')->on('users')->nullOnDelete();
            $table->string('auteurNom', 255)->nullable();
            $table->text('auteurPhotoUrl')->nullable();

            // ─── Date de publication ──────────────────────────────────────────
            $table->timestamp('date')->nullable();

            $table->timestamps();

            // ─── Index pour le fil d'actualité (tri par date) ────────────────
            $table->index(['churchId', 'type', 'date'], 'idx_church_type_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
