<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contributions et dons faits À un groupe (caisse du groupe), par opposition
 * à `groupeId` qui désigne le groupe DU contributeur pour une collecte de
 * l'église.
 *
 * `evenementId` devient nullable : une contribution de groupe n'appartient à
 * aucun événement de l'église, donc toutes les requêtes de trésorerie de
 * l'église (qui passent par l'événement) l'EXCLUENT naturellement — l'argent
 * d'un groupe ne se mélange jamais avec celui de l'église.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->unsignedBigInteger('evenementId')->nullable()->change();

            $table->foreignId('groupeCollecteId')->nullable()->after('groupeId')
                ->constrained('groupes')->restrictOnDelete();
            $table->foreignId('communiqueId')->nullable()->after('groupeCollecteId')
                ->constrained('groupe_communiques')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('communiqueId');
            $table->dropConstrainedForeignId('groupeCollecteId');
        });
    }
};
