<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table evenements — Module collecte de fonds (crowdfunding interne église)
 *
 * Etats : brouillon -> planifie -> actif -> termine -> archive (-> desarchive vers actif)
 * Aucune colonne deleted_at : suppression jamais exposee (tracabilite legale/comptable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evenements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // ─── Appartenance / auteur ────────────────────────────────────────
            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            $table->unsignedBigInteger('auteurId')->index();
            $table->foreign('auteurId')->references('id')->on('users')->cascadeOnDelete();

            // ─── Contenu ──────────────────────────────────────────────────────
            $table->string('titre', 150);
            $table->text('description');
            $table->text('imageUrl')->nullable();

            // ─── Etat ─────────────────────────────────────────────────────────
            $table->enum('statut', ['brouillon', 'planifie', 'actif', 'termine', 'archive'])
                ->default('brouillon')
                ->index();

            // ─── Dates du cycle de vie ────────────────────────────────────────
            $table->date('dateDebut');
            $table->timestamp('dateFin')->nullable();
            $table->timestamp('publishedAt')->nullable()->index();
            $table->timestamp('scheduledPublishAt')->nullable();
            $table->timestamp('termineAt')->nullable();
            $table->timestamp('archivedAt')->nullable();

            // ─── Objectif financier & totaux dénormalisés ─────────────────────
            $table->unsignedBigInteger('objectifMontant')->nullable();
            $table->unsignedBigInteger('montantCollecte')->default(0);
            $table->unsignedInteger('nombreContributions')->default(0);

            $table->timestamps();

            // ─── Index de recherche (perf à mesure que le volume augmente) ────
            $table->index(['churchId', 'statut', 'dateDebut'], 'idx_evenement_church_statut_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenements');
    }
};
