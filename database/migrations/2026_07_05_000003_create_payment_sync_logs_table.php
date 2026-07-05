<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Table `payment_sync_logs` : diagnostic TECHNIQUE verbeux
 * (chaque ping de vérification automatique auprès du gateway, y compris
 * ceux qui restent `pending`). Purgée après 7 jours par un job planifié —
 * distincte de `payment_events` qui est permanente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sync_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_transaction_id')->nullable()
                ->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('contribution_id')->nullable()
                ->constrained('contributions')->nullOnDelete();

            $table->timestamp('checked_at');
            $table->string('resulting_status', 20);
            $table->json('raw_payload')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_transaction_id', 'created_at']);
            $table->index(['contribution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sync_logs');
    }
};
