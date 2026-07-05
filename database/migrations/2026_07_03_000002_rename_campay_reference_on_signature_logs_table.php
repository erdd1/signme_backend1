<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Complète le nettoyage CamPay → NotchPay pour les tables
 * signature_logs et signatures (colonnes manquées dans la migration
 * précédente, 2026_07_03_000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_logs', function (Blueprint $table) {
            $table->renameColumn('campay_reference', 'payment_reference');
        });

        Schema::table('signatures', function (Blueprint $table) {
            $table->dropIndex('idx_signatures_campay_reference');
            $table->renameColumn('campay_reference', 'payment_reference');
            $table->index('payment_reference', 'idx_signatures_payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->dropIndex('idx_signatures_payment_reference');
            $table->renameColumn('payment_reference', 'campay_reference');
            $table->index('campay_reference', 'idx_signatures_campay_reference');
        });

        Schema::table('signature_logs', function (Blueprint $table) {
            $table->renameColumn('payment_reference', 'campay_reference');
        });
    }
};
