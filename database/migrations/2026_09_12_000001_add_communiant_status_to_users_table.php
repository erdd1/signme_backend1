<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `estCommuniant` — jusqu'ici implicite (tous les membres géraient la
 * sainte cène quel que soit leur rôle). Ne peut être `false` que pour le
 * rôle paroissien : les autres rôles restent communiants par construction
 * (imposé par UserService, jamais par l'utilisateur). Défaut `true` pour ne
 * rien changer aux comptes existants.
 *
 * `communiantDepuis` — posée uniquement lors du passage false→true par un
 * pasteur/secrétaire : sert de plancher (mois inclus) pour les signatures,
 * afin qu'un paroissien nouvellement communiant ne puisse pas signer des
 * mois passés pendant lesquels il ne l'était pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('estCommuniant')->default(true)->after('matricule');
            $table->date('communiantDepuis')->nullable()->after('estCommuniant');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['estCommuniant', 'communiantDepuis']);
        });
    }
};
