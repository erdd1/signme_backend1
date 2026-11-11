<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argent reçu hors application (cash) et enregistré par le président ou le
 * trésorier du groupe. Toujours distinct de l'argent reçu par l'application.
 * Immuable : une erreur s'annule (annuleAt + motif), elle ne se modifie pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupe_entrees_cash', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('groupeId')->constrained('groupes')->cascadeOnDelete();
            $table->foreignId('auteurId')->constrained('users')->restrictOnDelete();

            $table->unsignedBigInteger('montant');
            $table->string('donateur', 150)->nullable();
            $table->string('note', 255)->nullable();
            $table->date('dateEntree');

            $table->timestamp('annuleAt')->nullable();
            $table->foreignId('annuleParId')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motifAnnulation', 255)->nullable();

            $table->timestamps();

            $table->index(['groupeId', 'annuleAt', 'dateEntree'], 'idx_cash_groupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_entrees_cash');
    }
};
