<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le type d'événement.
 *
 * - collecte  : collecte de fonds classique (valeur de tous les événements existants)
 * - don_libre : événement permanent « Dons libres » d'une église, créé
 *               automatiquement au premier don (service « Faire un don »).
 *               Il porte les dons faits hors événement pour réutiliser tout le
 *               circuit de paiement, de trésorerie et de rapports ; il n'est
 *               jamais affiché dans le fil d'actualité ni dans la gestion.
 *
 * Migration additive : valeur par défaut « collecte », aucune donnée modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evenements', function (Blueprint $table) {
            $table->string('type', 20)->default('collecte')->after('statut')->index();
        });
    }

    public function down(): void
    {
        Schema::table('evenements', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
