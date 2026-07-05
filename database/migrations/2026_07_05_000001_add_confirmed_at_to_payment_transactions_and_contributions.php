<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Ajoute `confirmed_at` sur payment_transactions et contributions :
 * date exacte à laquelle le statut est devenu `completed`, distincte de
 * `updated_at` qui peut changer pour d'autres raisons. Renseignée une seule
 * fois (jamais écrasée) par processPaymentNotification().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('status');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
