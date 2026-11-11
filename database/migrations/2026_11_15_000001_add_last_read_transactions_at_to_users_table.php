<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dernière consultation de « Mes transactions » — base du badge de l'onglet
 * Services (nouvelle transaction effectuée). Les comptes existants partent de
 * « maintenant » : aucun badge parasite à la mise en production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_read_transactions_at')->nullable();
        });

        DB::table('users')->update(['last_read_transactions_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_read_transactions_at'));
    }
};
