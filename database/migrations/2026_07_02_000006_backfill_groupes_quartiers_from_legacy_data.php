<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migration de données — Transforme les anciennes valeurs texte libre
 * `users.groupe` / `users.quartierResidence` en entrées des nouvelles
 * listes gérées (une entrée par valeur distincte déjà utilisée dans chaque
 * église), puis relie chaque utilisateur à la bonne entrée. Aucune perte de
 * données : le pasteur retrouve directement ses groupes/quartiers déjà en
 * usage dans les nouvelles listes déroulantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ─── Groupes : users.groupe (texte) -> groupes + groupe_user + groupePrincipalId
        $groupeCache = [];

        DB::table('users')
            ->select('id', 'churchId', 'groupe')
            ->whereNotNull('churchId')
            ->whereNotNull('groupe')
            ->where('groupe', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$groupeCache, $now) {
                foreach ($users as $user) {
                    $nom = trim($user->groupe);
                    if ($nom === '') {
                        continue;
                    }
                    $key = $user->churchId . '::' . $nom;

                    if (!isset($groupeCache[$key])) {
                        $groupeId = DB::table('groupes')
                            ->where('churchId', $user->churchId)
                            ->where('nom', $nom)
                            ->value('id');

                        if (!$groupeId) {
                            $groupeId = DB::table('groupes')->insertGetId([
                                'uuid'       => (string) Str::uuid(),
                                'churchId'   => $user->churchId,
                                'nom'        => $nom,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }

                        $groupeCache[$key] = $groupeId;
                    }

                    $groupeId = $groupeCache[$key];

                    DB::table('users')->where('id', $user->id)->update(['groupePrincipalId' => $groupeId]);
                    DB::table('groupe_user')->insertOrIgnore([
                        'groupeId'   => $groupeId,
                        'userId'     => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

        // ─── Quartiers : users.quartierResidence (texte) -> quartiers + quartierResidenceId
        $quartierCache = [];

        DB::table('users')
            ->select('id', 'churchId', 'quartierResidence')
            ->whereNotNull('churchId')
            ->whereNotNull('quartierResidence')
            ->where('quartierResidence', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$quartierCache, $now) {
                foreach ($users as $user) {
                    $nom = trim($user->quartierResidence);
                    if ($nom === '') {
                        continue;
                    }
                    $key = $user->churchId . '::' . $nom;

                    if (!isset($quartierCache[$key])) {
                        $quartierId = DB::table('quartiers')
                            ->where('churchId', $user->churchId)
                            ->where('nom', $nom)
                            ->value('id');

                        if (!$quartierId) {
                            $quartierId = DB::table('quartiers')->insertGetId([
                                'uuid'       => (string) Str::uuid(),
                                'churchId'   => $user->churchId,
                                'nom'        => $nom,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }

                        $quartierCache[$key] = $quartierId;
                    }

                    DB::table('users')->where('id', $user->id)->update([
                        'quartierResidenceId' => $quartierCache[$key],
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Non réversible proprement (fusion possible de plusieurs utilisateurs
        // vers un même groupe/quartier) — les colonnes legacy `groupe` et
        // `quartierResidence` restent intactes en base pour permettre une
        // ré-analyse manuelle si nécessaire.
    }
};
