<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table evenement_state_transitions — Journal d'audit des changements
 * d'état d'un évenement (qui, quand, ancienne valeur, nouvelle valeur).
 *
 * Table append-only : jamais de mise à jour ni de suppression de ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evenement_state_transitions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('evenementId')->index();
            $table->foreign('evenementId')->references('id')->on('evenements')->cascadeOnDelete();

            // Null = transition automatique (scheduler), sinon utilisateur à l'origine
            $table->unsignedBigInteger('userId')->nullable();
            $table->foreign('userId')->references('id')->on('users')->nullOnDelete();

            // Null = création (pas d'état précédent)
            $table->string('ancienStatut', 20)->nullable();
            $table->string('nouveauStatut', 20);
            $table->string('raison', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenement_state_transitions');
    }
};
