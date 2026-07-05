<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Ajoute le groupe du contributeur sur chaque contribution
 * (auto-dérivé si l'utilisateur n'a qu'un seul groupe, sélectionné par lui
 * s'il en a plusieurs). Nullable : les utilisateurs sans groupe peuvent
 * toujours contribuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->unsignedBigInteger('groupeId')->nullable()->after('userId');
            $table->foreign('groupeId')->references('id')->on('groupes')->nullOnDelete();
            $table->index('groupeId');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropForeign(['groupeId']);
            $table->dropColumn('groupeId');
        });
    }
};
