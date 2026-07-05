<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Church;
use Illuminate\Support\Facades\Hash;

/**
 * TestUsersSeeder — Crée des utilisateurs de test
 *
 * Pour lancer : php artisan db:seed --class=TestUsersSeeder
 */
class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Récupérer ou créer une église pour les tests
        $church = Church::firstOrCreate(
            ['nom' => 'Église de Test', 'ville' => 'Douala'],
            [
                'region'    => 'Littoral',
                'adresse'   => 'Test Street',
                'telephone' => '+237 123 456 789',
                'email'     => 'test@church.cm',
            ]
        );

        $this->command->info("✅ Église de test : {$church->nom}");

        // 'role' est NOT NULL sans défaut en base : il doit être posé AVANT
        // le premier save() (pas d'INSERT intermédiaire sans lui).

        // Créer un paroissien de test
        $paroissien = User::where('email', 'paroissien@eec.cm')->first() ?? new User([
            'nom'      => 'Paroissien Test',
            'password' => Hash::make('password123'),
            'churchId' => $church->id,
        ]);
        $paroissien->role = User::ROLE_PAROISSIEN;
        $paroissien->actif = true;
        $paroissien->est_de_service = false;
        $paroissien->save();

        $this->command->info("✅ Paroissien créé : {$paroissien->email} / password123");

        // Créer un ancien de test
        $ancien = User::where('email', 'ancien@eec.cm')->first() ?? new User([
            'nom'      => 'Ancien Test',
            'password' => Hash::make('password123'),
            'churchId' => $church->id,
        ]);
        $ancien->role = User::ROLE_ANCIEN;
        $ancien->actif = true;
        $ancien->est_de_service = false;
        $ancien->save();

        $this->command->info("✅ Ancien créé : {$ancien->email} / password123");

        // Créer un pasteur pour vérifier qu'il est bloqué
        $pasteur = User::where('email', 'pasteur@eec.cm')->first() ?? new User([
            'nom'      => 'Pasteur Test',
            'password' => Hash::make('password123'),
            'churchId' => $church->id,
        ]);
        $pasteur->role = User::ROLE_PASTEUR;
        $pasteur->actif = true;
        $pasteur->est_de_service = false;
        $pasteur->save();

        $this->command->info("✅ Pasteur créé : {$pasteur->email} / password123");

        // Créer un secrétaire de test
        $secretaire = User::where('email', 'secretaire@eec.cm')->first() ?? new User([
            'nom'      => 'Secrétaire Test',
            'password' => Hash::make('password123'),
            'churchId' => $church->id,
        ]);
        $secretaire->role = User::ROLE_SECRETAIRE;
        $secretaire->actif = true;
        $secretaire->est_de_service = false;
        $secretaire->save();

        $this->command->info("✅ Secrétaire créé : {$secretaire->email} / password123");

        // Créer un trésorier de test
        $tresorier = User::where('email', 'tresorier@eec.cm')->first() ?? new User([
            'nom'      => 'Trésorier Test',
            'password' => Hash::make('password123'),
            'churchId' => $church->id,
        ]);
        $tresorier->role = User::ROLE_TRESORIER;
        $tresorier->actif = true;
        $tresorier->est_de_service = false;
        $tresorier->save();

        $this->command->info("✅ Trésorier créé : {$tresorier->email} / password123");

        $this->command->info("\n📋 Comptes de test créés :");
        $this->command->line("   PAROISSIEN:  paroissien@eec.cm / password123 ✓ (accès autorisé)");
        $this->command->line("   ANCIEN:      ancien@eec.cm / password123 ✓ (accès autorisé)");
        $this->command->line("   PASTEUR:     pasteur@eec.cm / password123 ✓ (accès autorisé)");
        $this->command->line("   SECRÉTAIRE:  secretaire@eec.cm / password123 ✓ (accès autorisé)");
        $this->command->line("   TRÉSORIER:   tresorier@eec.cm / password123 ✓ (accès autorisé)");
        $this->command->line("   ADMIN:       admin@eec.cm / Admin@EEC2026 ✗ (accès refusé)");
    }
}
