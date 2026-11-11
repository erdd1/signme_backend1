<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communiqués d'un groupe, publiés par son président. Jamais supprimés en
 * base : « supprimer » = archiver (les contributions déjà reçues restent
 * rattachées et visibles dans l'historique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupe_communiques', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('groupeId')->constrained('groupes')->cascadeOnDelete();
            $table->foreignId('auteurId')->constrained('users')->restrictOnDelete();

            $table->string('titre', 150);
            $table->text('message');
            $table->unsignedBigInteger('objectifMontant')->nullable();
            $table->date('dateDebut')->nullable();
            $table->date('dateFin')->nullable();
            $table->timestamp('archivedAt')->nullable();

            $table->timestamps();

            $table->index(['groupeId', 'archivedAt', 'created_at'], 'idx_communique_groupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_communiques');
    }
};
