<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des sauvegardes de base de données — une ligne par tentative
 * (planifiée, manuelle, ou de sécurité automatique avant une restauration).
 * `disks` liste les disques où le fichier a effectivement été écrit avec
 * succès (ex: ["backups-local"] ou ["backups-local", "backups-offsite"]) —
 * une sauvegarde peut réussir en local mais échouer côté hors-site (réseau,
 * identifiants invalides...), ce champ permet de le distinguer précisément
 * plutôt qu'un simple booléen "réussi/échoué".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('filename');
            $table->enum('type', ['scheduled', 'manual', 'pre_restore_safety'])->default('scheduled');
            $table->enum('status', ['pending', 'success', 'partial', 'failed'])->default('pending');

            // Disques où le fichier a été écrit avec succès (voir docblock ci-dessus).
            $table->json('disks')->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error_message')->nullable();

            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_backups_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
