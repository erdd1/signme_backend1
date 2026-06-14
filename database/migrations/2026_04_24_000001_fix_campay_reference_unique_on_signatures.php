<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX — Supprimer la contrainte UNIQUE sur campay_reference dans signatures.
 *
 * PROBLÈME : Une même référence Campay couvre PLUSIEURS mois (plusieurs lignes Signature).
 * La contrainte UNIQUE empêche Laravel de mettre la même campay_reference sur
 * toutes les lignes Signature du même paiement → le 2ème update échoue silencieusement
 * → les signatures restent à 'pending'.
 *
 * SOLUTION : Passer en index simple (non-unique) pour permettre plusieurs signatures
 * avec la même campay_reference (cas multi-mois).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            // 1. Supprimer la contrainte UNIQUE
            $table->dropUnique(['campay_reference']);

            // 2. Recréer un index simple (pour les performances de recherche webhook)
            $table->index('campay_reference', 'idx_signatures_campay_reference');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->dropIndex('idx_signatures_campay_reference');
            $table->unique('campay_reference');
        });
    }
};
