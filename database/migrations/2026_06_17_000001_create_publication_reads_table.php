<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table publication_reads
 *
 * Table pivot légère : chaque ligne = "cet utilisateur a lu cette publication".
 * Utilisée pour calculer le badge "Publications non lues" dans la navbar Home.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_reads', function (Blueprint $table) {
            $table->id();

            // ─── Relations ────────────────────────────────────────────────────
            $table->unsignedBigInteger('userId');
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('publicationId');
            $table->foreign('publicationId')->references('id')->on('publications')->cascadeOnDelete();

            $table->timestamp('readAt')->useCurrent();

            // ─── Contrainte : un utilisateur ne lit qu'une fois une publication ─
            $table->unique(['userId', 'publicationId'], 'uq_user_publication_read');

            // ─── Index pour le COUNT rapide des non-lus ───────────────────────
            $table->index('userId', 'idx_pub_reads_user');
            $table->index('publicationId', 'idx_pub_reads_publication');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_reads');
    }
};
