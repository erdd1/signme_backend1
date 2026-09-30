<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `matricule` — identifiant court propre à chaque église (ex. H011, F112),
 * distinct de la clé primaire `id`. Sert à accélérer la recherche d'un
 * membre dans "Gérer utilisateurs" et "Signature en présentiel". Unicité
 * imposée uniquement au sein d'une même église (composite churchId+matricule) :
 * deux églises différentes peuvent réutiliser le même matricule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('matricule', 4)->nullable()->after('sexe');
            $table->unique(['churchId', 'matricule']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['churchId', 'matricule']);
            $table->dropColumn('matricule');
        });
    }
};
