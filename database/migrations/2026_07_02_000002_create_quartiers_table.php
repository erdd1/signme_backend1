<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table quartiers — Quartiers de résidence, gérés par le
 * pasteur/la secrétaire. Un utilisateur sélectionne son quartier dans cette
 * liste plutôt que de le saisir en texte libre (facilite le tri/filtrage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quartiers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            $table->string('nom', 150);

            $table->unsignedBigInteger('createdBy')->nullable();
            $table->foreign('createdBy')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['churchId', 'nom'], 'uq_quartier_church_nom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quartiers');
    }
};
