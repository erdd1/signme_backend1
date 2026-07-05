<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table contributions — Contributions financières des paroissiens
 * aux évenements (module crowdfunding interne église).
 *
 * Table dédiée (et non payment_transactions) : une contribution est un paiement
 * one-shot avec sa propre grille de frais, distincte des paiements "signature"
 * (mensuels). Réutilise entièrement CampayGateway / Redis Queue / Webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('evenementId')->index();
            $table->foreign('evenementId')->references('id')->on('evenements')->cascadeOnDelete();

            $table->unsignedBigInteger('userId')->index();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            // ─── Montants (recalculés serveur, jamais confiance au client) ────
            $table->unsignedInteger('montant');
            $table->unsignedInteger('frais');
            $table->unsignedInteger('montantTotal');

            // ─── Note privée (visible auteur + pasteur/secretaire/tresorier) ──
            $table->string('note', 100)->nullable();

            // ─── Informations de paiement ──────────────────────────────────────
            $table->string('paymentOperator', 50);
            $table->string('paymentNumber', 20);

            // ─── Référence Campay (idempotence webhook) ───────────────────────
            $table->string('campay_reference', 255)->nullable()->unique();

            $table->enum('statut', ['pending', 'completed', 'failed'])->default('pending')->index();
            $table->json('campay_metadata')->nullable();

            $table->timestamps();

            $table->index(['userId', 'statut'], 'idx_contribution_user_statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
