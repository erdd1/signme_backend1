<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Church;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder Admin — Crée le premier compte administrateur EEC SignCard
 *
 * Pour lancer : php artisan db:seed --class=AdminSeeder
 * Ou via : php artisan db:seed
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Créer une première église (siège)
        $siege = Church::firstOrCreate(
            ['nom' => 'Direction Nationale EEC', 'ville' => 'Yaoundé'],
            [
                'region'    => 'Centre',
                'adresse'   => 'Centre Administratif EEC, Yaoundé',
                'telephone' => '+237 222 000 000',
                'email'     => 'direction@eec.cm',
            ]
        );

        $this->command->info("✅ Église créée : {$siege->nom}");

        // Créer le compte administrateur système
        // 'role' est NOT NULL sans défaut en base : il doit être posé
        // AVANT le premier save() (pas d'INSERT intermédiaire sans lui).
        $admin = User::where('email', 'admin@eec.cm')->first();
        if (!$admin) {
            $admin = new User([
                'nom'      => 'Administrateur EEC',
                'password' => Hash::make('Admin@EEC2026'),
                'churchId' => $siege->id,
            ]);
        }
        $admin->role = User::ROLE_ADMIN;
        $admin->actif = true;
        $admin->est_de_service = false;
        $admin->save();

        $this->command->info("✅ Admin créé : {$admin->email} / mot de passe : Admin@EEC2026");
        $this->command->warn("⚠️  Changez le mot de passe admin en production !");

        // Lier l'admin à l'église
        $siege->update(['pastorNom' => $admin->nom]);
    }
}
