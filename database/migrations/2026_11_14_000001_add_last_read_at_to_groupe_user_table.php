<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dernière visite de chaque membre dans chaque groupe — base des badges de
 * notification (nouveau communiqué, nouvelle transaction). Les appartenances
 * existantes sont initialisées à « maintenant » : aucun badge parasite à la
 * mise en production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_user', function (Blueprint $table) {
            $table->timestamp('lastReadAt')->nullable()->after('estTresorier');
        });

        DB::table('groupe_user')->update(['lastReadAt' => now()]);
    }

    public function down(): void
    {
        Schema::table('groupe_user', fn (Blueprint $table) => $table->dropColumn('lastReadAt'));
    }
};
