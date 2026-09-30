<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table celebration_types — Catalogue admin-gérable des occasions
 * ("Anniversaire", "Nouvel An", "Noël"…). Le contenu (titre/message) et
 * l'activation sont modifiables depuis le dashboard admin ; la ligne
 * elle-même n'est jamais supprimée (référencée par l'historique des
 * célébrations déjà envoyées) — seul `active` peut être basculé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('celebration_types', function (Blueprint $table) {
            $table->id();

            // ─── Identité ─────────────────────────────────────────────────────
            $table->string('key', 50)->unique();
            $table->string('label', 100);

            // ─── Contenu (modifiable par l'admin) ─────────────────────────────
            $table->string('titre', 150);
            // Gabarit avec placeholders {nom} et {eglise}, résolus à la lecture.
            $table->text('messageTemplate');

            // ─── Règle de date d'occurrence ────────────────────────────────────
            // 'fixed'    -> fixedMonth/fixedDay (même date chaque année)
            // 'birthday' -> dérivée de users.dateNaissance (par utilisateur)
            // 'easter'   -> calculée via easter_date() (fête mobile)
            $table->enum('dateRuleType', ['fixed', 'birthday', 'easter']);
            $table->unsignedTinyInteger('fixedMonth')->nullable();
            $table->unsignedTinyInteger('fixedDay')->nullable();

            // ─── Ciblage optionnel (ex. Journée de la femme -> 'femme' only) ──
            $table->enum('genderFilter', ['homme', 'femme'])->nullable();

            // ─── Présentation (frontend) ────────────────────────────────────
            $table->string('animationKey', 50);

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('celebration_types');
    }
};
