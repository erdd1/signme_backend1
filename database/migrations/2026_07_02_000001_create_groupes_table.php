<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table groupes — Ministères/groupes de l'église (ex. Chorale,
 * Jeunesse), gérés par le pasteur/la secrétaire. Un utilisateur peut
 * appartenir à plusieurs groupes (table pivot groupe_user) avec un groupe
 * principal (users.groupePrincipalId).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            $table->string('nom', 150);

            $table->unsignedBigInteger('createdBy')->nullable();
            $table->foreign('createdBy')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['churchId', 'nom'], 'uq_groupe_church_nom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupes');
    }
};
