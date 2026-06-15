<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table signatures — Collection centrale EEC SignCard
 *
 * Fusionne les collections Firestore : gifts + signatures
 * UNIQUE(userId, month) : un membre ne peut signer qu'une fois par mois.
 * campay_reference est en index simple (non-unique) car une même référence
 * Campay peut couvrir plusieurs mois (plusieurs lignes Signature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();

            // ─── Qui signe ? ──────────────────────────────────────────────────
            $table->unsignedBigInteger('userId')->index();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            // ─── Dénormalisation pour les rapports (évite les JOINs) ──────────
            $table->string('nomParoissien', 255);

            // ─── Quelle église ? ──────────────────────────────────────────────
            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            // ─── Période (mois) ───────────────────────────────────────────────
            $table->tinyInteger('monthIndex');               // 0 (Jan) à 11 (Déc)
            $table->string('month', 7);                     // YYYY-MM ex: 2026-04
            $table->smallInteger('year');

            // ─── Montant & Frais (FCFA) ───────────────────────────────────────
            $table->unsignedInteger('amount');              // Montant offrande
            $table->unsignedInteger('fees')->default(0);    // Frais de service (0 si présentiel)

            // ─── Mode de paiement ─────────────────────────────────────────────
            $table->string('paymentMethod', 50)->nullable(); // ESPECES / ORANGE MONEY / MTN MOBILE MONEY
            $table->string('phoneNumber', 20)->nullable();   // Numéro mobile (si distance)

            // ─── Type & Statut ────────────────────────────────────────────────
            $table->enum('signatureType', ['presentiel', 'distance']);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');

            // ─── Qui a signé (si présentiel) ? ───────────────────────────────
            $table->unsignedBigInteger('ancienId')->nullable();
            $table->foreign('ancienId')->references('id')->on('users')->nullOnDelete();
            $table->string('ancienNom', 255)->nullable();   // Dénormalisé

            // ─── Référence paiement Campay (si distance) ─────────────────────
            // Index simple (non-unique) : une référence Campay peut couvrir plusieurs mois
            $table->string('campay_reference', 255)->nullable();
            $table->index('campay_reference', 'idx_signatures_campay_reference');

            $table->timestamps();

            // ─── Contrainte d'unicité métier ──────────────────────────────────
            // Un paroissien ne peut signer qu'une fois par mois
            $table->unique(['userId', 'month'], 'uq_user_month_signature');

            // ─── Index pour les rapports ──────────────────────────────────────
            $table->index(['churchId', 'year', 'monthIndex'], 'idx_church_year_month');
            $table->index(['signatureType', 'status'], 'idx_type_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
