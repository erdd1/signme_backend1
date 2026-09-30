<?php

namespace App\Console\Commands;

use App\Models\Church;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crée une église (réutilisée si elle existe déjà par son nom) et lui
 * assigne un pasteur (réutilisé si l'église en a déjà un).
 *
 * Usage : php artisan app:create-church "EEC NLONKAK" Yaoundé --region=Centre
 */
class CreateChurchWithPastor extends Command
{
    protected $signature = 'app:create-church
        {nom : Nom de l\'église}
        {ville : Ville}
        {--region= : Région}
        {--pastor-nom= : Nom du pasteur (par défaut : "Pasteur <nom église>")}
        {--pastor-email= : Email du pasteur (par défaut généré depuis le nom de l\'église)}
        {--pastor-password=password123 : Mot de passe en clair du pasteur}';

    protected $description = 'Crée (ou réutilise) une église et lui assigne un pasteur';

    public function handle(): int
    {
        $nom = $this->argument('nom');

        $church = Church::firstOrCreate(
            ['nom' => $nom],
            [
                'ville'   => $this->argument('ville'),
                'region'  => $this->option('region'),
            ]
        );
        $this->info(
            ($church->wasRecentlyCreated ? '✅ Église créée' : 'ℹ️  Église déjà existante, réutilisée')
            . " : {$church->nom} (id={$church->id})"
        );

        $existingPastor = User::where('churchId', $church->id)
            ->where('role', User::ROLE_PASTEUR)
            ->first();
        if ($existingPastor) {
            $this->info("ℹ️  Pasteur déjà assigné : {$existingPastor->nom} ({$existingPastor->email})");
            return self::SUCCESS;
        }

        $slug = Str::slug($nom);
        $email = $this->option('pastor-email') ?: "pasteur.{$slug}@eec.cm";
        $pastorNom = $this->option('pastor-nom') ?: "Pasteur {$nom}";
        $password = $this->option('pastor-password');

        $pastor = User::where('email', $email)->first() ?? new User([
            'nom'      => $pastorNom,
            'email'    => $email,
            'password' => Hash::make($password),
            'churchId' => $church->id,
        ]);
        $pastor->role = User::ROLE_PASTEUR;
        $pastor->actif = true;
        $pastor->est_de_service = false;
        $pastor->estCommuniant = true;
        $pastor->churchId = $church->id;
        $pastor->save();

        $church->update(['pastorId' => $pastor->id, 'pastorNom' => $pastor->nom]);

        $this->info("✅ Pasteur assigné : {$pastor->nom} / {$email} / {$password}");

        return self::SUCCESS;
    }
}
