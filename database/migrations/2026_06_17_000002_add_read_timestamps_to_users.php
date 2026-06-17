<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajouter les timestamps de dernière lecture sur la table users.
 *
 * - last_read_historique_at           → badge Historique personnel (navbar)
 * - last_read_historique_signatures_at → badge Historique Signatures (drawer, ancien/pasteur)
 *
 * Principe : on compte les signatures créées APRÈS ce timestamp.
 * Null = jamais visité → toutes les signatures sont non lues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_read_historique_at')
                ->nullable()
                ->after('updated_at')
                ->comment('Dernière visite de l\'onglet Historique personnel');

            $table->timestamp('last_read_historique_signatures_at')
                ->nullable()
                ->after('last_read_historique_at')
                ->comment('Dernière visite de l\'onglet Historique Signatures (ancien/pasteur)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'last_read_historique_at',
                'last_read_historique_signatures_at',
            ]);
        });
    }
};
