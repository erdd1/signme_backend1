<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table villes — Villes de résidence, gérées par le pasteur/la
 * secrétaire. Chaque ville est marquée "Au Cameroun" ou "Hors Cameroun"
 * (estDiaspora) au moment de sa création — l'utilisateur choisit juste sa
 * ville, sans étape de sélection supplémentaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            $table->string('nom', 150);
            $table->boolean('estDiaspora')->default(false);

            $table->unsignedBigInteger('createdBy')->nullable();
            $table->foreign('createdBy')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['churchId', 'nom'], 'uq_ville_church_nom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villes');
    }
};
