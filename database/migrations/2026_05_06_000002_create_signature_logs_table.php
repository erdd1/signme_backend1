<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_logs', function (Blueprint $table) {
            $table->id();

            // ─── Utilisateur & Église ─────────────────────────────────────────
            $table->unsignedBigInteger('userId')->index();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();

            // ─── Mois signés ──────────────────────────────────────────────────
            $table->json('months');  // ["2026-01", "2026-02", ...]

            // ─── Montant & Frais ──────────────────────────────────────────────
            $table->unsignedInteger('amountPerMonth');
            $table->unsignedInteger('totalAmount');
            $table->unsignedInteger('fees');

            // ─── Mode de paiement (si distance) ────────────────────────────────
            $table->string('paymentMethod', 50)->nullable(); // ESPECES | ORANGE MONEY | MTN MOBILE MONEY
            $table->string('phoneNumber', 20)->nullable();

            // ─── Type & Statut ────────────────────────────────────────────────
            $table->enum('signatureType', ['presentiel', 'distance']);
            $table->enum('status', ['pending', 'completed', 'failed'])->index();

            // ─── Référence Campay (si distance) ────────────────────────────────
            $table->string('campay_reference', 255)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();

            // ─── Qui a signé en présentiel ? ───────────────────────────────────
            $table->unsignedBigInteger('ancienId')->nullable();
            $table->foreign('ancienId')->references('id')->on('users')->nullOnDelete();
            $table->string('ancienNom', 255)->nullable();

            // ─── Métadonnées & Erreurs ────────────────────────────────────────
            $table->string('errorMessage')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // ─── Index pour rapports admin ──────────────────────────────────────
            $table->index(['userId', 'status', 'created_at']);
            $table->index(['churchId', 'status', 'created_at']);
            $table->index(['signatureType', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_logs');
    }
};
