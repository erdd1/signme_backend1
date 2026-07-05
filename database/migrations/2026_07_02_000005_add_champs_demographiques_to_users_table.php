<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — Champs démographiques structurés sur users : sexe,
 * dateNaissance, et FK vers les nouvelles listes gérées (quartiers, villes,
 * groupes). Les anciennes colonnes texte libre `groupe`/`quartierResidence`
 * restent en base (aucune perte de données) mais ne sont plus utilisées par
 * le nouveau code — voir la migration de backfill suivante et
 * UserResource qui n'expose plus ces colonnes legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Données legacy invalides ('0000-00-00') qui bloquent la
        // réécriture de table déclenchée par l'ajout de contraintes FK
        // ci-dessous sous le mode strict MySQL — nettoyage sûr car
        // dateConfirmation est déjà nullable et ces valeurs sont inutilisables.
        DB::table('users')->where('dateConfirmation', '0000-00-00')->update(['dateConfirmation' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('sexe', ['homme', 'femme'])->nullable()->after('role');
            $table->date('dateNaissance')->nullable()->after('sexe');

            $table->unsignedBigInteger('quartierResidenceId')->nullable()->after('quartierResidence');
            $table->foreign('quartierResidenceId')->references('id')->on('quartiers')->nullOnDelete();

            $table->unsignedBigInteger('villeResidenceId')->nullable()->after('quartierResidenceId');
            $table->foreign('villeResidenceId')->references('id')->on('villes')->nullOnDelete();

            $table->unsignedBigInteger('groupePrincipalId')->nullable()->after('groupe');
            $table->foreign('groupePrincipalId')->references('id')->on('groupes')->nullOnDelete();

            $table->index('sexe');
            $table->index('dateNaissance');
            $table->index('quartierResidenceId');
            $table->index('villeResidenceId');
            $table->index('groupePrincipalId');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['quartierResidenceId']);
            $table->dropForeign(['villeResidenceId']);
            $table->dropForeign(['groupePrincipalId']);
            $table->dropColumn([
                'sexe',
                'dateNaissance',
                'quartierResidenceId',
                'villeResidenceId',
                'groupePrincipalId',
            ]);
        });
    }
};
