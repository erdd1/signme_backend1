<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table churches (Congrégations EEC)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('churches', function (Blueprint $table) {
            $table->id();

            // ─── Informations de base ─────────────────────────────────────────
            $table->string('nom');
            $table->string('ville', 100);
            $table->string('region', 100)->nullable();
            $table->text('adresse')->nullable();
            $table->string('telephone', 20)->nullable();
            $table->string('email', 255)->nullable();

            // ─── Lien avec le pasteur ─────────────────────────────────────────
            // Dénormalisé pour éviter les JOINs fréquents
            $table->unsignedBigInteger('pastorId')->nullable()->index();
            $table->string('pastorNom', 255)->nullable();

            $table->timestamps();
        });

        // Contrainte FK après création des deux tables
        // (users doit exister avant d'ajouter la FK churches.pastorId)
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('churchId')
                  ->references('id')
                  ->on('churches')
                  ->nullOnDelete();
        });

        Schema::table('churches', function (Blueprint $table) {
            $table->foreign('pastorId')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['churchId']);
        });

        Schema::table('churches', function (Blueprint $table) {
            $table->dropForeign(['pastorId']);
        });

        Schema::dropIfExists('churches');
    }
};
