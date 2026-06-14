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
        Schema::create('suspicious_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('activity_type'); // multiple_ips|different_countries|brute_force|unusual_time|etc
            $table->string('risk_level'); // low|medium|high|critical
            $table->string('ip_address');
            $table->string('country')->nullable();
            $table->string('device_id')->nullable();
            $table->json('details')->nullable(); // Infos supplémentaires
            $table->boolean('is_blocked')->default(false);
            $table->boolean('is_reviewed')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            // Index pour requêtes fréquentes
            $table->index(['user_id', 'risk_level', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspicious_activities');
    }
};
