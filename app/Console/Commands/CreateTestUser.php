<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Church;
use Illuminate\Support\Facades\Hash;

class CreateTestUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-test-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create test user for API testing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Create church
        $church = Church::firstOrCreate(
            ['email' => 'church@test.com'],
            [
                'nom' => 'Test Church',
                'telephone' => '1234567890',
                'adresse' => '123 Test St',
                'ville' => 'Test City',
                'codePostal' => '12345',
                'pays' => 'Test Country',
                'actif' => true,
            ]
        );

        $this->info("Church created/found: ID = {$church->id}");

        // Create test user
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'nom' => 'Test User',
                'role' => 'pasteur',
                'churchId' => $church->id,
                'password' => Hash::make('password123'),
                'actif' => true,
                'est_de_service' => false,
            ]
        );

        $this->info("User created/found: ID = {$user->id}, Email = {$user->email}");
        $this->info("\nTest credentials:");
        $this->info("Email: test@example.com");
        $this->info("Password: password123");
    }
}
