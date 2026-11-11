<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retraits de la caisse d'un groupe. `churchId` devient nullable : un retrait
 * de groupe n'a PAS d'église (il ne touche jamais au solde de l'église, dont
 * tous les calculs filtrent sur churchId) et porte `groupeId` à la place.
 * `motif` : raison du retrait, visible de tous les membres du groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retraits', function (Blueprint $table) {
            $table->unsignedBigInteger('churchId')->nullable()->change();

            $table->foreignId('groupeId')->nullable()->after('churchId')
                ->constrained('groupes')->restrictOnDelete();
            $table->string('motif', 255)->nullable()->after('motif_echec');

            $table->index(['groupeId', 'statut'], 'idx_retrait_groupe_statut');
        });
    }

    public function down(): void
    {
        Schema::table('retraits', function (Blueprint $table) {
            $table->dropIndex('idx_retrait_groupe_statut');
            $table->dropConstrainedForeignId('groupeId');
            $table->dropColumn('motif');
        });
    }
};
