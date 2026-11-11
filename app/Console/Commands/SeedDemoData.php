<?php

namespace App\Console\Commands;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Publication;
use App\Models\Quartier;
use App\Models\Signature;
use App\Models\User;
use App\Modules\Evenement\Application\Services\ContributionFeeCalculatorService;
use App\Modules\User\Application\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Génère des données de démonstration (utilisateurs, signatures,
 * contributions, événements, publications) — toutes reconnaissables et
 * supprimables d'un coup via `app:clear-demo-data` :
 *   - Utilisateurs   : email se terminant par "@signme.test"
 *   - Événements/Publications : titre préfixé "[TEST] "
 *   - Signatures/Contributions : rattachées aux utilisateurs de test ci-dessus
 *
 * Usage : php artisan app:seed-demo-data --church=1 --count=100
 *   --start=101                 première valeur du numéro "testNNN" (évite les collisions entre plusieurs lots)
 *   --domain=gmail.com          domaine des emails générés (email = "test{NNN}@{domain}")
 *   --password=12345678         mot de passe en clair des utilisateurs générés
 *   --min-signatures=1 --max-signatures=3   nombre de signatures par utilisateur (parmi les 12 derniers mois, 1 max/mois)
 *   --skip-evenements --skip-publications --skip-contributions   pour ne régénérer que utilisateurs/signatures
 */
class SeedDemoData extends Command
{
    protected $signature = 'app:seed-demo-data
        {--church= : ID de l\'église cible (par défaut : la première église)}
        {--count=100 : Nombre d\'utilisateurs de test à créer}
        {--start=1 : Numéro de départ pour "testNNN" (évite les collisions entre plusieurs lots)}
        {--domain=signme.test : Domaine des emails générés}
        {--password=password123 : Mot de passe en clair des utilisateurs générés}
        {--min-signatures=1 : Nombre minimum de signatures par utilisateur}
        {--max-signatures=3 : Nombre maximum de signatures par utilisateur (≤ 12, une par mois distinct)}
        {--skip-evenements : Ne pas générer d\'événements}
        {--skip-contributions : Ne pas générer de contributions}
        {--skip-publications : Ne pas générer de publications}';

    protected $description = 'Génère des utilisateurs/signatures/contributions/événements/publications de démonstration, facilement supprimables (app:clear-demo-data)';

    /** Marqueur universel des emails de démo, quel que soit le domaine choisi — voir ClearDemoData. */
    private const TITRE_PREFIX = '[TEST] ';

    private const PRENOMS_H = ['Jean', 'Pierre', 'Paul', 'Samuel', 'David', 'Emmanuel', 'Joseph', 'Daniel', 'Éric', 'Patrick', 'Michel', 'André', 'Blaise', 'Serge', 'Yves'];
    private const PRENOMS_F = ['Marie', 'Grace', 'Esther', 'Ruth', 'Sarah', 'Chantal', 'Sandrine', 'Bernadette', 'Florence', 'Nadège', 'Christelle', 'Pauline', 'Aline', 'Josiane', 'Rachel'];
    private const NOMS = ['Mballa', 'Ngono', 'Fouda', 'Essomba', 'Owona', 'Bella', 'Nkoulou', 'Abanda', 'Ateba', 'Tchoua', 'Kamdem', 'Nguema', 'Biya', 'Manga', 'Onana', 'Eyenga', 'Zang', 'Bikoro'];

    public function handle(): int
    {
        $church = $this->option('church')
            ? Church::findOrFail((int) $this->option('church'))
            : Church::firstOrFail();

        $count = max(1, (int) $this->option('count'));
        $start = max(1, (int) $this->option('start'));
        $domain = ltrim((string) $this->option('domain'), '@');
        $password = (string) $this->option('password');
        $minSignatures = max(0, (int) $this->option('min-signatures'));
        $maxSignatures = min(12, max($minSignatures, (int) $this->option('max-signatures')));

        $this->info("Église cible : {$church->nom} (id={$church->id})");

        DB::beginTransaction();
        try {
            $auteur = $this->resolveAuteur($church, $password);
            $groupeIds = Groupe::where('churchId', $church->id)->pluck('id')->all();
            $quartierIds = Quartier::where('churchId', $church->id)->pluck('id')->all();

            $users = $this->seedUsers($church, $count, $start, $domain, $password);
            $this->info("✅ {$users->count()} utilisateurs de test créés.");

            $this->attachDemographics($users, $groupeIds, $quartierIds);

            $signatureCount = $this->seedSignatures($church, $users, $minSignatures, $maxSignatures);
            $this->info("✅ {$signatureCount} signatures de test créées.");

            $evenements = collect();
            $contributionCount = 0;
            $publicationCount = 0;

            if (!$this->option('skip-evenements')) {
                $evenements = $this->seedEvenements($church, $auteur);
                $this->info("✅ {$evenements->count()} événements de test créés.");
            }

            if (!$this->option('skip-contributions') && $evenements->isNotEmpty()) {
                $contributionCount = $this->seedContributions($evenements, $users);
                $this->info("✅ {$contributionCount} contributions de test créées.");
            }

            if (!$this->option('skip-publications')) {
                $publicationCount = $this->seedPublications($church, $auteur);
                $this->info("✅ {$publicationCount} publications de test créées.");
            }

            DB::commit();

            $total = $users->count() + $signatureCount + $evenements->count() + $contributionCount + $publicationCount;
            $this->newLine();
            $this->info("🎉 Total : {$total} enregistrements de test créés.");
            $this->line("   Pour tout supprimer d'un coup : php artisan app:clear-demo-data");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Échec du seed : {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /** Réutilise un pasteur/secrétaire existant de l'église comme auteur des événements/publications, sinon en crée un dédié (email "test000@…", reconnu par app:clear-demo-data comme tout autre utilisateur de test). */
    private function resolveAuteur(Church $church, string $password): User
    {
        $existing = User::where('churchId', $church->id)
            ->whereIn('role', [User::ROLE_PASTEUR, User::ROLE_SECRETAIRE])
            ->first();
        if ($existing) {
            return $existing;
        }

        $auteur = new User([
            'nom'      => 'Auteur Démo',
            'email'    => 'test000@signme.test',
            'password' => Hash::make($password),
            'churchId' => $church->id,
        ]);
        $auteur->role = User::ROLE_PASTEUR;
        $auteur->actif = true;
        $auteur->est_de_service = false;
        $auteur->estCommuniant = true;
        $auteur->save();

        return $auteur;
    }

    private function seedUsers(Church $church, int $count, int $start, string $domain, string $password): \Illuminate\Support\Collection
    {
        $userService = app(UserService::class);
        $users = collect();
        $hashedPassword = Hash::make($password);

        for ($n = $start; $n < $start + $count; $n++) {
            $sexe = fake()->boolean(50) ? User::SEXE_HOMME : User::SEXE_FEMME;
            $prenom = $sexe === User::SEXE_HOMME
                ? self::PRENOMS_H[array_rand(self::PRENOMS_H)]
                : self::PRENOMS_F[array_rand(self::PRENOMS_F)];
            $nomFamille = self::NOMS[array_rand(self::NOMS)];
            $role = $n < $start + (int) round($count * 0.12) ? User::ROLE_ANCIEN : User::ROLE_PAROISSIEN;

            $user = new User([
                'nom'         => "{$prenom} {$nomFamille}",
                'email'       => "test{$n}@{$domain}",
                'password'    => $hashedPassword,
                'churchId'    => $church->id,
                'telephone'   => '6' . fake()->numerify('########'),
                'sexe'        => $sexe,
                'dateNaissance' => fake()->dateTimeBetween('-75 years', '-15 years')->format('Y-m-d'),
                'profession'  => fake()->jobTitle(),
                'originaireDe' => User::REGIONS[array_rand(User::REGIONS)],
            ]);
            $user->role = $role;
            $user->actif = true;
            $user->est_de_service = false;
            // false réservé au paroissien — voir User::MSG_NOT_COMMUNIANT.
            $user->estCommuniant = $role !== User::ROLE_PAROISSIEN || fake()->boolean(80);
            if ($user->estCommuniant) {
                $user->communiantDepuis = fake()->dateTimeBetween('-10 years', '-1 year')->format('Y-m-d');
            }
            $user->matricule = $userService->nextAvailableMatricule($user, $church->id, $sexe);
            $user->save();

            $users->push($user);
        }

        return $users;
    }

    private function attachDemographics(\Illuminate\Support\Collection $users, array $groupeIds, array $quartierIds): void
    {
        if (empty($groupeIds) && empty($quartierIds)) {
            return;
        }

        foreach ($users as $user) {
            $update = [];
            if (!empty($quartierIds) && fake()->boolean(70)) {
                $update['quartierResidenceId'] = $quartierIds[array_rand($quartierIds)];
            }
            if (!empty($groupeIds) && fake()->boolean(60)) {
                $groupeId = $groupeIds[array_rand($groupeIds)];
            }
            if ($update !== []) {
                $user->update($update);
            }
            if (isset($groupeId)) {
                DB::table('groupe_user')->insertOrIgnore([
                    'groupeId' => $groupeId,
                    'userId'   => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedSignatures(Church $church, \Illuminate\Support\Collection $users, int $minSignatures, int $maxSignatures): int
    {
        $anciens = $users->where('role', User::ROLE_ANCIEN);
        $count = 0;

        foreach ($users as $user) {
            // Si un minimum est exigé (>0), tout le monde en a — sinon 30 %
            // des utilisateurs n'ont volontairement aucune signature (réalisme).
            if ($minSignatures === 0 && !fake()->boolean(70)) {
                continue;
            }

            $nbMonths = fake()->numberBetween(max(1, $minSignatures), max($minSignatures, $maxSignatures));
            // 12 derniers mois, 1 signature max/mois (contrainte unique userId+month).
            $months = collect(range(0, 11))
                ->shuffle()
                ->take($nbMonths)
                ->map(fn ($offset) => now()->subMonths($offset));

            foreach ($months as $date) {
                $isDistance = fake()->boolean(55);
                $status = fake()->randomElement([
                    Signature::STATUS_COMPLETED, Signature::STATUS_COMPLETED,
                    Signature::STATUS_COMPLETED, Signature::STATUS_PENDING, Signature::STATUS_FAILED,
                ]);
                $ancien = $anciens->isNotEmpty() && !$isDistance ? $anciens->random() : null;

                try {
                    Signature::create([
                        'userId'        => $user->id,
                        'nomParoissien' => $user->nom,
                        'churchId'      => $church->id,
                        'monthIndex'    => (int) $date->format('n') - 1,
                        'month'         => $date->format('Y-m'),
                        'year'          => (int) $date->format('Y'),
                        'amount'        => 1000,
                        'fees'          => $isDistance ? 100 : 0,
                        'paymentMethod' => $isDistance ? fake()->randomElement([Signature::PAYMENT_ORANGE, Signature::PAYMENT_MTN]) : Signature::PAYMENT_ESPECES,
                        'phoneNumber'   => $isDistance ? '6' . fake()->numerify('########') : null,
                        'signatureType' => $isDistance ? Signature::TYPE_DISTANCE : Signature::TYPE_PRESENTIEL,
                        'status'        => $status,
                        'ancienId'      => $ancien?->id,
                        'ancienNom'     => $ancien?->nom,
                        'payment_reference' => $isDistance ? 'DEMO-SIG-' . Str::random(10) : null,
                    ]);
                    $count++;
                } catch (\Illuminate\Database\QueryException) {
                    // Doublon (userId, month) déjà généré — ignoré, pas bloquant.
                }
            }
        }

        return $count;
    }

    private function seedEvenements(Church $church, User $auteur): \Illuminate\Support\Collection
    {
        $modeles = [
            ['titre' => 'Construction du nouveau temple', 'objectif' => 5000000, 'statut' => Evenement::STATUT_ACTIF],
            ['titre' => 'Aide aux orphelins de la paroisse', 'objectif' => 800000, 'statut' => Evenement::STATUT_ACTIF],
            ['titre' => 'Rentrée scolaire 2026', 'objectif' => 1200000, 'statut' => Evenement::STATUT_ACTIF],
            ['titre' => 'Journée missionnaire', 'objectif' => null, 'statut' => Evenement::STATUT_ACTIF],
            ['titre' => 'Réfection de la toiture', 'objectif' => 2000000, 'statut' => Evenement::STATUT_TERMINE],
            ['titre' => 'Fête de fin d\'année 2025', 'objectif' => 500000, 'statut' => Evenement::STATUT_TERMINE],
            ['titre' => 'Achat de matériel de sonorisation', 'objectif' => 900000, 'statut' => Evenement::STATUT_ARCHIVE],
            ['titre' => 'Camp de jeunesse 2026', 'objectif' => 600000, 'statut' => Evenement::STATUT_PLANIFIE],
            ['titre' => 'Brouillon — collecte à définir', 'objectif' => null, 'statut' => Evenement::STATUT_BROUILLON],
        ];

        $evenements = collect();
        foreach ($modeles as $m) {
            $dateDebut = now()->subDays(fake()->numberBetween(5, 90));
            $isTermine = $m['statut'] === Evenement::STATUT_TERMINE || $m['statut'] === Evenement::STATUT_ARCHIVE;

            $evenement = Evenement::create([
                'uuid'        => (string) Str::uuid(),
                'churchId'    => $church->id,
                'auteurId'    => $auteur->id,
                'titre'       => self::TITRE_PREFIX . $m['titre'],
                'description' => fake()->paragraphs(2, true),
                'statut'      => $m['statut'],
                'dateDebut'   => $dateDebut,
                'dateFin'     => $isTermine ? $dateDebut->copy()->addDays(30) : null,
                'publishedAt' => $m['statut'] === Evenement::STATUT_BROUILLON || $m['statut'] === Evenement::STATUT_PLANIFIE ? null : $dateDebut,
                'scheduledPublishAt' => $m['statut'] === Evenement::STATUT_PLANIFIE ? now()->addDays(3) : null,
                'termineAt'   => $isTermine ? $dateDebut->copy()->addDays(30) : null,
                'archivedAt'  => $m['statut'] === Evenement::STATUT_ARCHIVE ? now()->subDays(2) : null,
                'objectifMontant' => $m['objectif'],
                'montantCollecte' => 0,
                'nombreContributions' => 0,
            ]);
            $evenements->push($evenement);
        }

        return $evenements;
    }

    private function seedContributions(\Illuminate\Support\Collection $evenements, \Illuminate\Support\Collection $users): int
    {
        $ciblables = $evenements->whereIn('statut', [Evenement::STATUT_ACTIF, Evenement::STATUT_TERMINE]);
        $montants = [500, 1000, 2000, 5000, 10000, 25000, 50000];
        $count = 0;

        foreach ($ciblables as $evenement) {
            $nbContributions = fake()->numberBetween(3, 10);
            $contributeurs = $users->random(min($nbContributions, $users->count()));

            foreach ($contributeurs as $user) {
                $montant = $montants[array_rand($montants)];
                $calcul = ContributionFeeCalculatorService::calculateTotal($montant);

                $contribution = Contribution::create([
                    'uuid'            => (string) Str::uuid(),
                    'evenementId'     => $evenement->id,
                    'userId'          => $user->id,
                    'montant'         => $calcul['montant'],
                    'frais'           => $calcul['frais'],
                    'montantTotal'    => $calcul['total'],
                    'paymentOperator' => fake()->randomElement(['ORANGE MONEY', 'MTN MOBILE MONEY']),
                    'paymentNumber'   => '6' . fake()->numerify('########'),
                    'paymentMethod'   => Contribution::METHOD_MOBILE_MONEY,
                    'payment_reference' => 'DEMO-CONTRIB-' . Str::random(10),
                    'statut'          => Contribution::STATUT_COMPLETED,
                ]);
                $count++;

                $evenement->increment('montantCollecte', $contribution->montant);
                $evenement->increment('nombreContributions');
            }
        }

        return $count;
    }

    private function seedPublications(Church $church, User $auteur): int
    {
        $annonces = [
            'Réunion de prière ce vendredi à 18h',
            'Collecte de vêtements pour les nécessiteux',
            'Formation des nouveaux membres du chœur',
            'Assemblée générale annuelle',
            'Journée de jeûne et prière',
            'Inscriptions ouvertes pour le camp jeunesse',
            'Nouvel horaire des cultes du dimanche',
            'Visite pastorale dans les familles',
        ];
        $meditations = [
            'La grâce suffit',
            'Marcher par la foi',
            'L\'amour qui persévère',
            'La force dans la faiblesse',
            'Un cœur reconnaissant',
            'La paix qui surpasse toute intelligence',
        ];

        $count = 0;
        foreach ($annonces as $titre) {
            Publication::create([
                'churchId'    => $church->id,
                'type'        => Publication::TYPE_ANNONCE,
                'titre'       => self::TITRE_PREFIX . $titre,
                'contenu'     => fake()->paragraphs(3, true),
                'auteurId'    => $auteur->id,
                'auteurNom'   => $auteur->nom,
                'date'        => now()->subDays(fake()->numberBetween(0, 60)),
            ]);
            $count++;
        }
        foreach ($meditations as $titre) {
            Publication::create([
                'churchId'          => $church->id,
                'type'              => Publication::TYPE_MEDITATION,
                'titre'             => self::TITRE_PREFIX . $titre,
                'contenu'           => fake()->paragraphs(4, true),
                'referenceBiblique' => fake()->randomElement(['Jean 3:16', 'Psaume 23:1', 'Philippiens 4:13', 'Romains 8:28']),
                'auteurId'          => $auteur->id,
                'auteurNom'         => $auteur->nom,
                'date'              => now()->subDays(fake()->numberBetween(0, 60)),
            ]);
            $count++;
        }

        return $count;
    }
}
