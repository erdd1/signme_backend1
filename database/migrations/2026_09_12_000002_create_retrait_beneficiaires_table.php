<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte de retrait (Orange Money / MTN Mobile Money) d'un pasteur/trésorier —
 * enregistré une fois auprès de NotchPay (Beneficiary::create), réutilisé pour
 * chaque retrait. Modifiable : on désactive l'ancien et on en crée un nouveau
 * (historique conservé, jamais de mutation d'un enregistrement déjà utilisé
 * par un retrait passé — même logique d'immutabilité que payment_events).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retrait_beneficiaires', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('userId')->constrained('users')->cascadeOnDelete();
            $table->foreignId('churchId')->constrained('churches')->cascadeOnDelete();

            $table->string('channel', 20);           // cm.orange | cm.mtn
            $table->text('account_number');           // chiffré (cast 'encrypted')
            $table->string('name', 150);              // nom du titulaire (= User::nom)

            $table->string('notchpay_beneficiary_id', 100)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['userId', 'is_active'], 'idx_retrait_benef_user_actif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrait_beneficiaires');
    }
};
