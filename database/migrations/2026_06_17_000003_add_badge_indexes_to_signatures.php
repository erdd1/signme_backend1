<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes de performance pour les compteurs de badges.
 *
 * Ces indexes optimisent les COUNT() sur la table signatures
 * pour le calcul des badges :
 *   - Badge Historique personnel : WHERE userId=? AND status='completed' AND created_at > ?
 *   - Badge Historique Signatures : WHERE ancienId=? AND created_at > ?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            // Badge historique personnel : COUNT par userId + status + date
            $table->index(
                ['userId', 'status', 'created_at'],
                'idx_sig_badge_historique'
            );

            // Badge historique signatures (ancien/pasteur) : COUNT par ancienId + date
            $table->index(
                ['ancienId', 'created_at'],
                'idx_sig_badge_historique_signatures'
            );
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->dropIndex('idx_sig_badge_historique');
            $table->dropIndex('idx_sig_badge_historique_signatures');
        });
    }
};
