<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sorties_financieres', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('churchId')->index();
            $table->foreign('churchId')->references('id')->on('churches')->cascadeOnDelete();
            $table->unsignedBigInteger('userId')->index();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->enum('type', ['simple', 'frais']);
            // simple => approved dès création | frais => pending jusqu'à validation admin
            $table->enum('statut', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedInteger('montant');
            $table->string('description', 255);
            $table->string('reference', 255)->nullable();  // preuve/référence externe
            $table->string('motif_rejet', 500)->nullable(); // rempli par admin si rejected
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->foreign('validated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            // Solde théorique après cette sortie (calculé à la création, NULL pour frais pending)
            $table->unsignedBigInteger('solde_apres')->nullable();
            $table->timestamps();
            $table->index(['churchId', 'statut'], 'idx_sortie_church_statut');
            $table->index(['churchId', 'type'],   'idx_sortie_church_type');
            $table->index(['churchId', 'created_at'], 'idx_sortie_church_date');
        });
    }
    public function down(): void {
        Schema::dropIfExists('sorties_financieres');
    }
};
