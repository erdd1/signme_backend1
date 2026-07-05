<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table groupe_user — Appartenance multiple : un utilisateur peut
 * appartenir à plusieurs groupes. Le groupe "principal" (par défaut) est
 * stocké séparément sur users.groupePrincipalId (doit toujours être l'un
 * des groupes de cette table pivot, vérifié côté service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupe_user', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('groupeId')->index();
            $table->foreign('groupeId')->references('id')->on('groupes')->cascadeOnDelete();

            $table->unsignedBigInteger('userId')->index();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['groupeId', 'userId'], 'uq_groupe_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_user');
    }
};
