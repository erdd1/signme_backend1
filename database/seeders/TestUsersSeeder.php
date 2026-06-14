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

        // Créer un paroissien de test
        $paroissien = User::firstOrCreate(
            ['email' => 'paroissien@eec.cm'],
            [
                'nom'            => 'Paroissien Test',
                'password'       => Hash::make('password123'),
                'role'           => User::ROLE_PAROISSIEN,
                'churchId'       => $church->id,
                'actif'          => true,
                'est_de_service' => false,
            ]
        );

        $this->command->info("✅ Paroissien créé : {$paroissien->email} / password123");

        // Créer un ancien de test
        $ancien = User::firstOrCreate(
            ['email' => 'ancien@eec.cm'],
            [
                'nom'            => 'Ancien Test',
                'password'       => Hash::make('password123'),
                'role'           => User::ROLE_ANCIEN,
                'churchId'       => $church->id,
                'actif'          => true,
                'est_de_service' => false,
            ]
        );

        $this->command->info("✅ Ancien créé : {$ancien->email} / password123");

        // Créer un pasteur pour vérifier qu'il est bloqué
        $pasteur = User::firstOrCreate(
            ['email' => 'pasteur@eec.cm'],
            [
                'nom'            => 'Pasteur Test',
                'password'       => Hash::make('password123'),
                'role'           => User::ROLE_PASTEUR,
                'churchId'       => $church->id,
                'actif'          => true,
                'est_de_service' => false,
            ]
        );

        $this->command->info("✅ Pasteur créé : {$pasteur->email} / password123");

        // Créer un secrétaire de test
        $secretaire = User::firstOrCreate(
            ['email' => 'secretaire@eec.cm'],
            [
                'nom'            => 'Secrétaire Test',
                'password'       => Hash::make('password123'),
                'role'           => User::ROLE_SECRETAIRE,
                'churchId'       => $church->id,
                'actif'          => true,
                'est_de_service' => false,
            ]
        );

        $this->command->info("✅ Secrétaire créé : {$secretaire->email} / password123");

        // Créer un trésorier de test
        $tresorier = User::firstOrCreate(
            ['email' => 'tresorier@eec.cm'],
            [
                'nom'            => 'Trésorier Test',
                'password'       => Hash::make('password123'),
                'role'           => User::ROLE_TRESORIER,
                'churchId'       => $church->id,
                'actif'          => true,
                'est_de_service' => false,
            ]
        );

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
