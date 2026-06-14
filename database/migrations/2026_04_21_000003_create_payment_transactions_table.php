<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table payment_transactions — Logs des paiements mobiles
 *
 * Stocke chaque tentative de paiement Campay (mobile money).
 * Liée aux signatures via la table pivot signature_payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();

            // ─── Qui paye ? ───────────────────────────────────────────────────
            $table->unsignedBigInteger('userId')->index();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            // ─── Informations de paiement ─────────────────────────────────────
            $table->string('paymentOperator', 50);          // ORANGE MONEY / MTN MOBILE MONEY
            $table->string('paymentNumber', 20);            // Numéro de téléphone mobile
            $table->unsignedInteger('amountPerMonth');      // Montant par mois
            $table->tinyInteger('monthsCount')->default(1); // Nombre de mois à signer
            $table->unsignedInteger('totalAmount');         // Total offrandes
            $table->unsignedInteger('fees')->default(0);    // Total frais
            $table->unsignedInteger('amountCharged');       // Montant réellement débité

            // ─── Référence Campay ─────────────────────────────────────────────
            $table->string('campay_reference', 255)->nullable()->unique();

            // ─── Statut du paiement ───────────────────────────────────────────
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->enum('signatureType', ['presentiel', 'distance'])->default('distance');

            // ─── Métadonnées Campay (réponse brute) ───────────────────────────
            $table->json('campay_metadata')->nullable();    // Stocker la réponse JSON complète

            $table->timestamps();

            $table->index(['status', 'campay_reference'], 'idx_payment_status_ref');
        });

        // ─── Table pivot signatures ↔ payment_transactions ────────────────────
        Schema::create('signature_payment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('signature_id');
            $table->foreign('signature_id')->references('id')->on('signatures')->cascadeOnDelete();
            $table->unsignedBigInteger('payment_transaction_id');
            $table->foreign('payment_transaction_id')->references('id')->on('payment_transactions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['signature_id', 'payment_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_payment');
        Schema::dropIfExists('payment_transactions');
    }
};
