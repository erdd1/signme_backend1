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
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->ipAddress('ip_address')->index();
            $table->string('user_agent')->nullable();
            $table->integer('failed_count')->default(0);
            $table->timestamp('first_attempt_at'); // Premier essai du cycle
            $table->timestamp('blocked_until')->nullable(); // Blocage temporaire
            $table->string('status')->default('pending'); // pending|success|blocked
            $table->timestamps();

            // Index pour requêtes fréquentes
            $table->index(['email', 'ip_address', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
