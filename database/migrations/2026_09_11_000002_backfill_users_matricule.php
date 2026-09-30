<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attribue un matricule (H001-H999 / F001-F999) à chaque membre déjà en
 * base, par église et par sexe (ordre d'ancienneté = ordre d'id). Les
 * quelques membres sans `sexe` renseigné sont des comptes de test
 * (aucun profil réel concerné à ce jour) : ils reçoivent `homme` par défaut
 * pour pouvoir recevoir un matricule. Un membre sans église (aucun
 * `churchId`) ne peut pas être scopé et reste sans matricule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('sexe')
            ->whereNotNull('churchId')
            ->update(['sexe' => User::SEXE_HOMME]);

        $churchIds = DB::table('users')->whereNotNull('churchId')->distinct()->pluck('churchId');

        foreach ($churchIds as $churchId) {
            foreach ([User::SEXE_HOMME => 'H', User::SEXE_FEMME => 'F'] as $sexe => $prefix) {
                $userIds = DB::table('users')
                    ->where('churchId', $churchId)
                    ->where('sexe', $sexe)
                    ->orderBy('id')
                    ->pluck('id');

                $numero = 1;
                foreach ($userIds as $userId) {
                    DB::table('users')->where('id', $userId)->update([
                        'matricule' => $prefix . str_pad((string) $numero, 3, '0', STR_PAD_LEFT),
                    ]);
                    $numero++;
                }
            }
        }
    }

    public function down(): void
    {
        // Pas de retour arrière : le sexe attribué par défaut ne doit pas
        // être effacé (donnée désormais réelle), le matricule non plus.
    }
};
