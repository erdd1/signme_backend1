<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postes dans un groupe (président, trésorier) portés par le pivot
 * groupe_user : un membre sans aucun des deux est « simple membre ».
 * Un membre peut cumuler président + trésorier, jamais « simple membre » en
 * plus (c'est le défaut quand aucun poste n'est coché). Ces postes n'ont rien
 * à voir avec users.role (le rôle « tresorier » de l'église reste inchangé).
 *
 * Un groupe ayant des mouvements d'argent ne se supprime plus : il s'archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_user', function (Blueprint $table) {
            $table->boolean('estPresident')->default(false)->after('userId');
            $table->boolean('estTresorier')->default(false)->after('estPresident');
        });

        Schema::table('groupes', function (Blueprint $table) {
            $table->timestamp('archivedAt')->nullable()->after('createdBy');
        });
    }

    public function down(): void
    {
        Schema::table('groupes', fn (Blueprint $table) => $table->dropColumn('archivedAt'));
        Schema::table('groupe_user', fn (Blueprint $table) => $table->dropColumn(['estPresident', 'estTresorier']));
    }
};
