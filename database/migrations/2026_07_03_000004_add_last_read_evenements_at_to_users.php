<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le timestamp de dernière lecture des Événements (badge navbar Accueil,
 * combiné avec le badge Publications) — même principe que
 * last_read_historique_at : on compte les événements publiés APRÈS ce
 * timestamp. Null = jamais visité → tous les événements visibles sont non lus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_read_evenements_at')
                ->nullable()
                ->after('last_read_historique_signatures_at')
                ->comment('Dernière visite du fil d\'actualité (badge Événements, onglet Accueil)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_read_evenements_at');
        });
    }
};
