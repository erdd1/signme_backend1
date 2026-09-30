<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retraits d'argent (pasteur/trésorier) vers Orange Money / MTN Mobile Money,
 * via NotchPay Transfer. `montant_demande` est ce qui est déduit du solde
 * interne de l'église (voir SortieFinanciereService::getDashboardStats) ;
 * `frais` est retenu sur ce montant ; `montant_net` (= montant_demande - frais)
 * est ce qui est réellement envoyé au bénéficiaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retraits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('churchId')->constrained('churches')->cascadeOnDelete();
            $table->foreignId('userId')->constrained('users')->cascadeOnDelete();
            $table->foreignId('retrait_beneficiaire_id')->constrained('retrait_beneficiaires')->restrictOnDelete();

            $table->unsignedInteger('montant_demande'); // déduit du solde interne
            $table->unsignedInteger('frais');
            $table->unsignedInteger('montant_net');     // envoyé à NotchPay (= reçu par le bénéficiaire)

            $table->enum('statut', ['pending', 'processing', 'completed', 'failed'])->default('pending');

            $table->string('reference', 100)->unique();        // notre référence, transmise à NotchPay
            $table->string('notchpay_transfer_id', 100)->nullable();
            $table->string('notchpay_status', 30)->nullable();  // statut brut NotchPay (debug/support)
            $table->string('motif_echec', 500)->nullable();

            $table->unsignedBigInteger('solde_apres')->nullable(); // solde théorique après ce retrait
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['churchId', 'statut'], 'idx_retrait_church_statut');
            $table->index(['churchId', 'created_at'], 'idx_retrait_church_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retraits');
    }
};
