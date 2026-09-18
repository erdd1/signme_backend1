<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue le "super admin" (accès total, illimité) des "mini-admins"
 * (accès restreint module par module via `admin_permissions`). Les
 * administrateurs déjà existants sont automatiquement promus super admin
 * lors de la migration — sans ça, personne n'aurait le droit de créer ou
 * gérer les mini-admins après coup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('role');
        });

        DB::table('users')->where('role', 'administrateur')->update(['is_super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
