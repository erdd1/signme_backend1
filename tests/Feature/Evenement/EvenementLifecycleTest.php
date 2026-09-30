<?php

namespace Tests\Feature\Evenement;

use App\Core\Services\ImageStorageService;
use App\Models\Church;
use App\Models\Evenement;
use App\Models\User;
use App\Modules\Evenement\Application\Jobs\ArchiveExpiredEvenementsJob;
use App\Modules\Evenement\Application\Jobs\PublishScheduledEvenementsJob;
use App\Modules\Evenement\Application\Jobs\TerminateExpiredEvenementsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EvenementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Effectue les requêtes suivantes authentifié en tant que $user.
     *
     * Nécessaire car le guard Sanctum (RequestGuard) met en cache l'utilisateur
     * résolu sur sa propre instance ; sans réinitialisation, deux requêtes avec
     * des jetons différents dans le même test résoudraient le même utilisateur.
     */
    private function asUser(User $user): TestCase
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer ' . $user->createToken('test')->plainTextToken);
    }

    private function pasteur(Church $church): User
    {
        return User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
    }

    public function test_titre_and_description_length_are_validated(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);

        $response = $this->asUser($pasteur)->postJson('/api/v1/evenements', [
            'titre'       => 'abc', // trop court
            'description' => 'trop court', // < 20 caractères
            'dateDebut'   => now()->toDateString(),
            'mode'        => 'brouillon',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['titre', 'description']);
    }

    public function test_date_debut_cannot_be_in_the_past(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);

        $response = $this->asUser($pasteur)->postJson('/api/v1/evenements', [
            'titre'       => 'Excellence scolaire',
            'description' => 'Collecte pour soutenir les enfants brillants de notre église.',
            'dateDebut'   => now()->subDays(2)->toDateString(),
            'mode'        => 'brouillon',
        ]);

        $response->assertStatus(400);
    }

    public function test_date_fin_must_be_after_date_debut(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);

        $response = $this->asUser($pasteur)->postJson('/api/v1/evenements', [
            'titre'       => 'Construction du temple',
            'description' => 'Collecte pour financer la construction du nouveau temple.',
            'dateDebut'   => now()->toDateString(),
            'dateFin'     => now()->subDay()->toIso8601String(),
            'mode'        => 'brouillon',
        ]);

        $response->assertStatus(422);
    }

    public function test_paroissien_cannot_create_evenement(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $response = $this->asUser($paroissien)->postJson('/api/v1/evenements', [
            'titre'       => 'Aide aux orphelins',
            'description' => 'Collecte pour soutenir les orphelins de notre communauté.',
            'dateDebut'   => now()->toDateString(),
            'mode'        => 'brouillon',
        ]);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_create_evenement(): void
    {
        $response = $this->postJson('/api/v1/evenements', [
            'titre'       => 'Aide aux orphelins',
            'description' => 'Collecte pour soutenir les orphelins de notre communauté.',
            'dateDebut'   => now()->toDateString(),
            'mode'        => 'brouillon',
        ]);

        $response->assertStatus(401);
    }

    public function test_brouillon_is_visible_only_to_author(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $autre = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Camp evangelisation',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_BROUILLON,
            'dateDebut'   => now(),
        ]);

        // L'auteur voit son brouillon
        $this->asUser($auteur)
            ->getJson("/api/v1/evenements/{$evenement->uuid}")
            ->assertStatus(200);

        // Un autre utilisateur ne le voit pas (404, pas de fuite d'information)
        $this->asUser($autre)
            ->getJson("/api/v1/evenements/{$evenement->uuid}")
            ->assertStatus(404);
    }

    public function test_immediate_publication_is_visible_to_all_and_active(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);

        $response = $this->asUser($pasteur)->postJson('/api/v1/evenements', [
            'titre'       => 'Achat instruments',
            'description' => 'Collecte pour acheter de nouveaux instruments de musique.',
            'dateDebut'   => now()->toDateString(),
            'mode'        => 'immediate',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.statut', Evenement::STATUT_ACTIF);
        $response->assertJsonPath('data.peutContribuer', true);
    }

    public function test_scheduled_publication_is_invisible_until_scheduled_time_then_auto_publishes(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);
        $autre = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $scheduledAt = now()->addHour();

        $response = $this->asUser($pasteur)->postJson('/api/v1/evenements', [
            'titre'              => 'Soutien aux malades',
            'description'        => 'Collecte pour soutenir les malades de notre église.',
            'dateDebut'          => now()->toDateString(),
            'mode'               => 'planifie',
            'scheduledPublishAt' => $scheduledAt->toIso8601String(),
        ]);

        $response->assertStatus(201);
        $uuid = $response->json('data.uuid');

        // Invisible pour un autre utilisateur avant publication
        $this->asUser($autre)
            ->getJson("/api/v1/evenements/{$uuid}")
            ->assertStatus(404);

        // On avance le temps après la date planifiée et on exécute le job
        Carbon::setTestNow($scheduledAt->copy()->addMinute());
        (new PublishScheduledEvenementsJob())->handle();
        Carbon::setTestNow();

        $evenement = Evenement::where('uuid', $uuid)->first();
        $this->assertSame(Evenement::STATUT_ACTIF, $evenement->statut);
        $this->assertNotNull($evenement->publishedAt);

        // Visible maintenant pour un autre utilisateur
        $this->asUser($autre)
            ->getJson("/api/v1/evenements/{$uuid}")
            ->assertStatus(200);

        $this->assertDatabaseHas('evenement_state_transitions', [
            'evenementId'   => $evenement->id,
            'ancienStatut'  => Evenement::STATUT_PLANIFIE,
            'nouveauStatut' => Evenement::STATUT_ACTIF,
        ]);
    }

    public function test_planifie_evenement_can_be_published_manually_before_scheduled_time(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);
        $autre = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $scheduledAt = now()->addDays(3);

        $create = $this->asUser($pasteur)->postJson('/api/v1/evenements', [
            'titre'              => 'Achat instruments musique',
            'description'        => 'Collecte pour acheter de nouveaux instruments de musique.',
            'dateDebut'          => now()->toDateString(),
            'mode'               => 'planifie',
            'scheduledPublishAt' => $scheduledAt->toIso8601String(),
        ]);
        $uuid = $create->json('data.uuid');

        // L'auteur change d'avis et publie avant la date/heure planifiée.
        $response = $this->asUser($pasteur)->postJson("/api/v1/evenements/{$uuid}/publish");

        $response->assertStatus(200);
        $response->assertJsonPath('data.statut', Evenement::STATUT_ACTIF);
        $response->assertJsonPath('data.scheduledPublishAt', null);

        $evenement = Evenement::where('uuid', $uuid)->first();
        $this->assertSame(Evenement::STATUT_ACTIF, $evenement->statut);
        $this->assertNotNull($evenement->publishedAt);
        $this->assertNull($evenement->scheduledPublishAt);

        // Visible immédiatement pour un autre utilisateur, sans attendre la date planifiée.
        $this->asUser($autre)
            ->getJson("/api/v1/evenements/{$uuid}")
            ->assertStatus(200);
    }

    public function test_cannot_publish_an_already_active_evenement(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $pasteur->id,
            'titre'       => 'Excellence scolaire deja actif',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);

        $this->asUser($pasteur)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/publish")
            ->assertStatus(400);
    }

    public function test_cancel_scheduled_publication_permanently_deletes_it(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);

        $create = $this->asUser($pasteur)->postJson('/api/v1/evenements', [
            'titre'              => 'Camp evangelisation jeunesse',
            'description'        => 'Collecte pour financer le camp d\'évangélisation des jeunes.',
            'dateDebut'          => now()->toDateString(),
            'mode'               => 'planifie',
            'scheduledPublishAt' => now()->addDay()->toIso8601String(),
        ]);
        $uuid = $create->json('data.uuid');

        $this->asUser($pasteur)
            ->postJson("/api/v1/evenements/{$uuid}/cancel-scheduled")
            ->assertStatus(200);

        $this->assertDatabaseMissing('evenements', ['uuid' => $uuid]);
    }

    public function test_cancel_scheduled_publication_fails_once_published(): void
    {
        $church = Church::factory()->create();
        $pasteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $pasteur->id,
            'titre'       => 'Camp evangelisation jeunesse',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);

        $this->asUser($pasteur)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/cancel-scheduled")
            ->assertStatus(400);

        $this->assertDatabaseHas('evenements', ['uuid' => $evenement->uuid]);
    }

    public function test_only_author_can_update_even_another_pasteur_cannot(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $autrePasteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Excellence scolaire 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);

        $this->asUser($autrePasteur)
            ->putJson("/api/v1/evenements/{$evenement->uuid}", ['titre' => 'Titre modifie valide'])
            ->assertStatus(403);

        $this->asUser($auteur)
            ->putJson("/api/v1/evenements/{$evenement->uuid}", ['titre' => 'Titre modifie valide'])
            ->assertStatus(200);
    }

    /**
     * Bonus de la migration Cloudinary → stockage local : sur disque local,
     * une ancienne image jamais nettoyée est une fuite d'espace disque réelle
     * (contrairement à Cloudinary, sans conséquence à leur échelle) — voir
     * EvenementService::update().
     */
    public function test_update_with_new_image_deletes_previous_image(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $this->mock(ImageStorageService::class, function ($mock) {
            $mock->shouldReceive('generateEvenementFilename')->andReturn('evenement_new');
            $mock->shouldReceive('uploadEvenementImage')
                ->andReturn('https://api.test/storage/evenements/evenement_new.jpg');
            $mock->shouldReceive('deleteByUrl')
                ->once()
                ->with('https://api.test/storage/evenements/evenement_old.jpg');
        });

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Excellence scolaire 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
            'imageUrl'    => 'https://api.test/storage/evenements/evenement_old.jpg',
        ]);

        $this->asUser($auteur)
            ->put("/api/v1/evenements/{$evenement->uuid}", [
                'titre' => 'Titre modifie valide',
                'image' => UploadedFile::fake()->image('nouvelle.jpg'),
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('evenements', [
            'uuid'     => $evenement->uuid,
            'imageUrl' => 'https://api.test/storage/evenements/evenement_new.jpg',
        ]);
    }

    public function test_cannot_update_terminated_or_archived_evenement(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Aide orphelins 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_TERMINE,
            'dateDebut'   => now(),
            'termineAt'   => now(),
        ]);

        $this->asUser($auteur)
            ->putJson("/api/v1/evenements/{$evenement->uuid}", ['titre' => 'Titre modifie valide'])
            ->assertStatus(403);
    }

    public function test_terminer_manually_blocks_future_contributions(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Achat instruments 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);

        $this->asUser($auteur)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/terminer")
            ->assertStatus(200)
            ->assertJsonPath('data.statut', Evenement::STATUT_TERMINE);
    }

    public function test_terminate_expired_evenements_job_auto_terminates_on_date_fin(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Aide malades 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now()->subDays(5),
            'dateFin'     => now()->subHour(),
            'publishedAt' => now()->subDays(5),
        ]);

        (new TerminateExpiredEvenementsJob())->handle();

        $this->assertSame(Evenement::STATUT_TERMINE, $evenement->fresh()->statut);
    }

    public function test_archive_expired_evenements_job_auto_archives_after_3_days(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Camp jeunesse 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_TERMINE,
            'dateDebut'   => now()->subDays(10),
            'termineAt'   => now()->subDays(3)->subMinute(),
        ]);

        // app()->call() résout les dépendances injectées dans handle() (comme
        // le ferait Schedule::job() via le bus) — un appel direct ->handle()
        // sans passer par le conteneur ne le peut pas.
        $this->app->call([new ArchiveExpiredEvenementsJob(), 'handle']);

        $this->assertSame(Evenement::STATUT_ARCHIVE, $evenement->fresh()->statut);
    }

    /**
     * Régression : sans scheduler/queue worker actif (ex. environnement de
     * dev), un événement terminé depuis plus de 3 jours restait visible
     * indéfiniment dans le fil et le compte à rebours affichait toujours
     * "archivage dans 3 jours" — car seul le job planifié (jamais exécuté)
     * effectuait la transition termine -> archive. Le fil doit maintenant
     * s'auto-corriger à chaque consultation, sans dépendre d'aucun job.
     */
    public function test_feed_lazily_archives_overdue_evenement_without_any_job(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $membre = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Camp jeunesse 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_TERMINE,
            'dateDebut'   => now()->subDays(10),
            'publishedAt' => now()->subDays(10),
            'termineAt'   => now()->subDays(4), // 4 jours > seuil de 3 jours
        ]);

        // Aucun job/scheduler appelé ici — uniquement la requête de liste.
        $response = $this->asUser($membre)->getJson('/api/v1/evenements');

        $response->assertStatus(200);
        $uuids = collect($response->json('data.data') ?? $response->json('data'))->pluck('uuid');
        $this->assertNotContains($evenement->uuid, $uuids);

        $this->assertSame(Evenement::STATUT_ARCHIVE, $evenement->fresh()->statut);
        $this->assertNotNull($evenement->fresh()->archivedAt);
    }

    public function test_archiver_manuel_by_secretaire_who_is_not_author(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $secretaire = User::factory()->create(['role' => 'secretaire', 'churchId' => $church->id]);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Construction temple annexe',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_TERMINE,
            'dateDebut'   => now()->subDays(5),
            'termineAt'   => now(),
        ]);

        // Le secrétaire n'est pas l'auteur mais peut archiver (droit non restreint à l'auteur)
        $this->asUser($secretaire)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/archiver")
            ->assertStatus(200)
            ->assertJsonPath('data.statut', Evenement::STATUT_ARCHIVE);
    }

    public function test_desarchiver_is_author_only(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $autrePasteur = $this->pasteur($church);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Soutien malades hopital',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ARCHIVE,
            'dateDebut'   => now()->subDays(10),
            'archivedAt'  => now(),
        ]);

        // Un autre pasteur ne voit même pas l'événement archivé d'autrui (404, pas de fuite
        // d'information) : seul l'auteur (ou l'admin) a une visibilité sur un événement archivé.
        $this->asUser($autrePasteur)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/desarchiver")
            ->assertStatus(404);

        $this->asUser($auteur)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/desarchiver")
            ->assertStatus(200)
            ->assertJsonPath('data.statut', Evenement::STATUT_ACTIF);
    }

    public function test_admin_bypasses_author_restriction_for_update_and_desarchiver(): void
    {
        $church = Church::factory()->create();
        $auteur = $this->pasteur($church);
        $admin = User::factory()->create(['role' => 'administrateur', 'churchId' => $church->id, 'is_super_admin' => true]);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Excellence scolaire admin',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ARCHIVE,
            'dateDebut'   => now()->subDays(10),
            'archivedAt'  => now(),
        ]);

        $this->asUser($admin)
            ->postJson("/api/admin/evenements/{$evenement->uuid}/desarchiver")
            ->assertStatus(200)
            ->assertJsonPath('data.statut', Evenement::STATUT_ACTIF);
    }
}
