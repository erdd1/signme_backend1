<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration table device_tokens — Jetons FCM (Firebase Cloud Messaging) par
 * appareil, pour les notifications push (nouvelles publications/événements,
 * anniversaires). Un jeton correspond à une seule installation d'app : s'il
 * réapparaît pour un autre utilisateur (nouveau login sur le même appareil),
 * il est réassigné plutôt que dupliqué (contrainte unique sur `token`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('userId')->index();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            $table->string('token', 512);
            $table->string('platform', 20); // android | ios
            $table->string('timezone', 64)->nullable(); // IANA, ex. Africa/Douala
            $table->timestamp('lastSeenAt')->nullable();

            $table->timestamps();

            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
