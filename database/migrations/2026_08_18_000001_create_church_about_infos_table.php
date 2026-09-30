<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table church_about_infos — Module "À propos de mon Église"
 *
 * Relation 1-1 avec churches. Contient UNIQUEMENT les informations
 * permanentes du Card principal (district, horaires de culte, activités
 * majeures récurrentes) — jamais les articles éditoriaux, qui vivent dans
 * about_church_articles.
 *
 * Table dédiée plutôt que colonnes ajoutées à `churches` : `churches` est
 * une table transversale utilisée par de nombreux autres modules déjà en
 * production (signatures, publications, désignations…) — l'isoler limite
 * le risque de régression sur ces modules existants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_about_infos', function (Blueprint $table) {
            $table->id();

            // ─── Relation 1-1 avec l'église ────────────────────────────────────
            $table->unsignedBigInteger('churchId')->unique();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            // ─── Informations saisies par pasteur/secrétaire ──────────────────
            $table->string('district', 150)->nullable();
            // Chaîne libre : "Dimanche 9h-11h", "Dim 8h & 10h", etc.
            $table->string('horairesCulte', 255)->nullable();
            // Array JSON de chaînes courtes, max 5 entrées (validé côté service).
            // Une entrée = une ligne affichée dans le Card principal.
            $table->json('activitesMajeures')->nullable();

            // ─── Traçabilité de la dernière modification ──────────────────────
            $table->unsignedBigInteger('updatedBy')->nullable();
            $table->foreign('updatedBy')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_about_infos');
    }
};
