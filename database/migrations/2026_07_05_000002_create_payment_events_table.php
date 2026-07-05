<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Table `payment_events` : historique MÉTIER, compact et
 * immuable (INSERT-only, jamais purgé) des événements marquants d'un
 * paiement (créé, envoyé au gateway, webhook reçu, confirmé, échoué, expiré,
 * revérifié manuellement). Exactement une des deux FK (payment_transaction_id
 * / contribution_id) est renseignée par ligne — appliqué côté modèle
 * (PaymentEvent::record()), pas en contrainte DB (portabilité SQLite/MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_transaction_id')->nullable()
                ->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('contribution_id')->nullable()
                ->constrained('contributions')->nullOnDelete();

            $table->string('event_type', 30);
            $table->string('resulting_status', 20);
            $table->json('raw_payload')->nullable();

            $table->foreignId('triggered_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('source_ip', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_transaction_id', 'created_at']);
            $table->index(['contribution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
