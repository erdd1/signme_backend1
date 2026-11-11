<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une entrée cash peut être rattachée à un communiqué du groupe (ex. « Dons
 * de reconnaissance ») et au membre qui a remis l'argent en main propre.
 * Les deux sont facultatifs : un don externe (ex. le parrain) reste saisi en
 * texte libre (`donateur`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_entrees_cash', function (Blueprint $table) {
            $table->foreignId('communiqueId')->nullable()->after('auteurId')
                ->constrained('groupe_communiques')->restrictOnDelete();
            $table->foreignId('membreId')->nullable()->after('communiqueId')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groupe_entrees_cash', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membreId');
            $table->dropConstrainedForeignId('communiqueId');
        });
    }
};
