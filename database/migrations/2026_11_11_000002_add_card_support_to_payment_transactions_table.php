<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement de signature à distance par carte bancaire (Flutterwave).
 *
 * - paymentGateway : fournisseur du paiement (notchpay = Mobile Money, valeur
 *   de toutes les transactions existantes ; flutterwave = carte)
 * - paymentOperator et paymentNumber deviennent facultatifs : un paiement par
 *   carte n'a ni opérateur ni numéro de téléphone (la carte est saisie sur la
 *   page hébergée du fournisseur).
 *
 * Migration additive : aucune donnée existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('paymentGateway', 20)->default('notchpay')->after('paymentMethod');
            $table->string('paymentOperator', 50)->nullable()->change();
            $table->string('paymentNumber', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('paymentGateway');
            // paymentOperator / paymentNumber restent facultatifs : les remettre
            // en NOT NULL échouerait dès qu'un paiement par carte existe.
        });
    }
};
