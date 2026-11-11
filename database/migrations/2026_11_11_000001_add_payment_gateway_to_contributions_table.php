<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le fournisseur de paiement de chaque contribution.
 *
 * - notchpay    : Mobile Money (Orange Money / MTN) — valeur de toutes les
 *                 contributions existantes
 * - flutterwave : carte bancaire (Visa, Mastercard…) via la page de paiement
 *                 hébergée
 *
 * Migration additive : valeur par défaut « notchpay », aucune donnée modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('paymentGateway', 20)->default('notchpay')->after('paymentMethod');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn('paymentGateway');
        });
    }
};
