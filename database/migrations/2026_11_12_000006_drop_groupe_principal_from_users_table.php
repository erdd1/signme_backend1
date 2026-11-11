<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le « groupe principal » n'a plus de raison d'être : un chrétien appartient
 * à plusieurs groupes à égalité (avec un poste dans chacun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['groupePrincipalId']);
            $table->dropIndex(['groupePrincipalId']);
            $table->dropColumn('groupePrincipalId');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('groupePrincipalId')->nullable();
            $table->foreign('groupePrincipalId')->references('id')->on('groupes')->nullOnDelete();
            $table->index('groupePrincipalId');
        });
    }
};
