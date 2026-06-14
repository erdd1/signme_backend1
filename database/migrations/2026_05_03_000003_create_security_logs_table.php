<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action'); // login|logout|failed_login|password_reset|token_refresh|blocked|suspicious_activity
            $table->string('status'); // success|failed|blocked|alert
            $table->ipAddress('ip_address');
            $table->string('user_agent')->nullable();
            $table->string('device_id')->nullable();
            $table->json('details')->nullable(); // Données sécurisées (sans mots de passe)
            $table->timestamp('created_at')->index();

            // Relation
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');

            // Index pour audit trails
            $table->index(['action', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_logs');
    }
};
