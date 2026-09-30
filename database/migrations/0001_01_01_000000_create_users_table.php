<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table users — EEC SignCard
 *
 * Tous les membres (6 rôles) sont dans la même table.
 * Champs identiques aux noms Firebase pour faciliter la migration des données.
 * Inclut les colonnes de sécurité (MFA, sessions, audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // ─── Informations de base ─────────────────────────────────────────
            $table->string('nom');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', [
                'administrateur',
                'pasteur',
                'secretaire',
                'tresorier',
                'ancien',
                'paroissien',
            ]);

            // ─── Appartenance à une église ────────────────────────────────────
            // FK vers churches - nullable pour admin (accès global)
            $table->unsignedBigInteger('churchId')->nullable()->index();

            // ─── Statut du compte ─────────────────────────────────────────────
            $table->boolean('actif')->default(true);
            // Uniquement pour les anciens : désigné de service ce mois
            $table->boolean('est_de_service')->default(false);

            // ─── Photo de profil (URL publique, stockage local) ────────────────
            $table->text('photoUrl')->nullable();

            // ─── Informations de profil ───────────────────────────────────────
            $table->string('telephone', 20)->nullable();
            $table->string('quartierResidence', 150)->nullable();
            $table->string('profession', 150)->nullable();
            $table->string('groupe', 100)->nullable();          // Chorale / groupe
            $table->string('originaireDe', 100)->nullable();    // Région d'origine

            // ─── Informations de confirmation chrétienne ──────────────────────
            $table->date('dateConfirmation')->nullable();
            $table->string('lieuConfirmation', 255)->nullable();

            // ─── Sécurité — Email ─────────────────────────────────────────────
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('email_verified')->default(false);

            // ─── Sécurité — Notifications & Audit ────────────────────────────
            $table->boolean('security_notifications_enabled')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->integer('failed_login_attempts')->default(0);

            // ─── Tokens & Session ─────────────────────────────────────────────
            $table->rememberToken();

            $table->timestamps();
        });

        // Table pour la réinitialisation de mot de passe
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Table de sessions (pour les connexions web si nécessaire)
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
