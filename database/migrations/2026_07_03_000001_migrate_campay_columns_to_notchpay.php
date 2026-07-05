<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Remplacement de CamPay par NotchPay : renomme les colonnes
 * de référence/métadonnées de paiement (générique, plus attaché à un
 * agrégateur en particulier), et ajoute le choix de la méthode de paiement
 * (mobile_money | card) désormais possible avec NotchPay. `paymentOperator`/
 * `paymentNumber` deviennent nullable : un paiement carte n'a ni opérateur
 * mobile money ni numéro de téléphone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->renameColumn('campay_reference', 'payment_reference');
            $table->renameColumn('campay_metadata', 'payment_metadata');
            $table->string('paymentOperator', 50)->nullable()->change();
            $table->string('paymentNumber', 20)->nullable()->change();
            $table->string('paymentMethod', 20)->default('mobile_money')->after('paymentNumber');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->renameColumn('campay_reference', 'payment_reference');
            $table->renameColumn('campay_metadata', 'payment_metadata');
            $table->string('paymentOperator', 50)->nullable()->change();
            $table->string('paymentNumber', 20)->nullable()->change();
            $table->string('paymentMethod', 20)->default('mobile_money')->after('paymentNumber');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('paymentMethod');
            $table->string('paymentOperator', 50)->nullable(false)->change();
            $table->string('paymentNumber', 20)->nullable(false)->change();
            $table->renameColumn('payment_reference', 'campay_reference');
            $table->renameColumn('payment_metadata', 'campay_metadata');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn('paymentMethod');
            $table->string('paymentOperator', 50)->nullable(false)->change();
            $table->string('paymentNumber', 20)->nullable(false)->change();
            $table->renameColumn('payment_reference', 'campay_reference');
            $table->renameColumn('payment_metadata', 'campay_metadata');
        });
    }
};
