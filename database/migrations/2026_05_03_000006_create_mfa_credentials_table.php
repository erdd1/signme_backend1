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
        Schema::create('m_f_a_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique()->index();
            $table->boolean('mfa_enabled')->default(false);
            $table->string('mfa_type')->default('email'); // email|totp (extensible)
            $table->string('mfa_secret')->nullable(); // Pour TOTP future
            $table->json('backup_codes')->nullable(); // Codes de secours
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_f_a_credentials');
    }
};
