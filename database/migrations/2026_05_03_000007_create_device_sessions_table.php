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
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('device_id')->nullable()->unique();
            $table->string('device_name')->nullable(); // iPhone 12, Samsung Galaxy, etc
            $table->string('device_type')->nullable(); // mobile|web|tablet
            $table->string('os')->nullable(); // iOS|Android|Windows|MacOS
            $table->string('os_version')->nullable(); // 14.5, 12, etc
            $table->string('app_version')->nullable(); // Pour tracking version app
            $table->string('browser')->nullable(); // Chrome, Safari, etc (si web)
            $table->ipAddress('ip_address');
            $table->string('country')->nullable(); // Géolocalisation (ex: CM)
            $table->string('city')->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            // Index pour requêtes fréquentes
            $table->index(['user_id', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
