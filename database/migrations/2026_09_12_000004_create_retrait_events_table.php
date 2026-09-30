<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique MÉTIER, compact et immuable (INSERT-only), des événements d'un
 * retrait (créé, envoyé à NotchPay, webhook reçu, revérifié manuellement) —
 * même conception que payment_events, pour le statut "exact" exigé sur les
 * retraits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retrait_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('retrait_id')->constrained('retraits')->cascadeOnDelete();

            $table->string('event_type', 30);
            $table->string('resulting_status', 20);
            $table->json('raw_payload')->nullable();

            $table->foreignId('triggered_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('source_ip', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['retrait_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrait_events');
    }
};
