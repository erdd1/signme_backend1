<?php

namespace Tests\Feature\AboutChurch;

use App\Core\Services\ImageStorageService;
use App\Models\AboutChurchArticle;
use App\Models\Church;
use App\Models\ChurchAboutInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - Module AboutChurch ("À propos de mon Église").
 *
 * Couvre :
 *  - RBAC : lecture ouverte à tous les rôles, écriture pasteur/secrétaire uniquement
 *  - Payload agrégé (church + info + articles)
 *  - Maj des infos permanentes (district/horaires/activités majeures)
 *  - CRUD articles (validation, isolation inter-église)
 *  - Épinglage/désépinglage (quotas par catégorie, photo obligatoire, pasteur "ancien" exclu)
 */
class AboutChurchClientTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function church(): Church
    {
        return Church::factory()->create();
    }

    private function pasteur(Church $church): User
    {
        return User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id, 'actif' => true]);
    }

    private function secretaire(Church $church): User
    {
        return User::factory()->create(['role' => 'secretaire', 'churchId' => $church->id, 'actif' => true]);
    }

    private function tresorier(Church $church): User
    {
        return User::factory()->create(['role' => 'tresorier', 'churchId' => $church->id, 'actif' => true]);
    }

    private function paroissien(Church $church): User
    {
        return User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id, 'actif' => true]);
    }

    private function article(Church $church, string $type = AboutChurchArticle::TYPE_ACTIVITY, array $overrides = []): AboutChurchArticle
    {
        return AboutChurchArticle::create(array_merge([
            'uuid'      => (string) Str::uuid(),
            'churchId'  => $church->id,
            'type'      => $type,
            'titre'     => 'Titre de test',
            'contenu'   => 'Contenu de test suffisamment long.',
            'auteurNom' => 'Testeur',
        ], $overrides));
    }

    /** Remplace ImageStorageService par un mock qui n'écrit jamais sur disque. */
    private function mockImageUpload(): void
    {
        $this->mock(ImageStorageService::class, function ($mock) {
            $mock->shouldReceive('generateAboutChurchArticleFilename')
                ->andReturnUsing(fn ($uuid, $index) => "about_church_{$uuid}_{$index}");
            $mock->shouldReceive('uploadAboutChurchArticleImage')
                ->andReturnUsing(fn ($file, $filename) => "https://api.test/storage/about_church/{$filename}.jpg");
            $mock->shouldReceive('deleteByUrl')->andReturnNull();
        });
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    public function test_guest_cannot_view_about_church(): void
    {
        $this->getJson('/api/v1/about-church')->assertStatus(401);
    }

    public function test_all_authenticated_roles_can_view_about_church(): void
    {
        $church = $this->church();

        foreach ([$this->pasteur($church), $this->secretaire($church), $this->tresorier($church), $this->paroissien($church)] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/about-church')->assertStatus(200);
        }
    }

    public function test_paroissien_cannot_update_info(): void
    {
        Sanctum::actingAs($this->paroissien($this->church()));
        $this->putJson('/api/v1/about-church/info', ['district' => 'Nord'])->assertStatus(403);
    }

    public function test_tresorier_cannot_create_article(): void
    {
        Sanctum::actingAs($this->tresorier($this->church()));
        $this->postJson('/api/v1/about-church/articles', [
            'type' => AboutChurchArticle::TYPE_ACTIVITY,
            'titre' => 'Une activité',
            'contenu' => 'Contenu suffisamment long pour passer la validation.',
        ])->assertStatus(403);
    }

    public function test_secretaire_can_create_article(): void
    {
        Sanctum::actingAs($this->secretaire($this->church()));
        $this->postJson('/api/v1/about-church/articles', [
            'type' => AboutChurchArticle::TYPE_ACTIVITY,
            'titre' => 'Une activité',
            'contenu' => 'Contenu suffisamment long pour passer la validation.',
        ])->assertStatus(201);
    }

    // =========================================================================
    // PAYLOAD AGRÉGÉ
    // =========================================================================

    public function test_show_returns_church_info_and_articles(): void
    {
        $church = $this->church();
        $this->article($church, AboutChurchArticle::TYPE_ACTIVITY);
        Sanctum::actingAs($this->paroissien($church));

        $this->getJson('/api/v1/about-church')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['church', 'info', 'articles']])
            ->assertJsonCount(1, 'data.articles');
    }

    public function test_show_isolation_inter_eglises(): void
    {
        $church1 = $this->church();
        $church2 = $this->church();
        $this->article($church1);
        $this->article($church1);

        Sanctum::actingAs($this->paroissien($church2));

        $this->getJson('/api/v1/about-church')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.articles');
    }

    // =========================================================================
    // UPDATE INFO
    // =========================================================================

    public function test_pasteur_can_update_info(): void
    {
        $church = $this->church();
        Sanctum::actingAs($this->pasteur($church));

        $this->putJson('/api/v1/about-church/info', [
            'district' => 'District du Centre',
            'horairesCulte' => 'Dimanche 9h-12h',
            'activitesMajeures' => ['Réunion de prière', 'Journée jeunesse'],
        ])->assertStatus(200)
          ->assertJsonPath('data.district', 'District du Centre')
          ->assertJsonPath('data.horairesCulte', 'Dimanche 9h-12h')
          ->assertJsonCount(2, 'data.activitesMajeures');

        $this->assertDatabaseHas('church_about_infos', [
            'churchId' => $church->id,
            'district' => 'District du Centre',
        ]);
    }

    public function test_update_info_rejects_more_than_max_activites(): void
    {
        Sanctum::actingAs($this->pasteur($this->church()));

        $this->putJson('/api/v1/about-church/info', [
            'activitesMajeures' => array_fill(0, ChurchAboutInfo::MAX_ACTIVITES_MAJEURES + 1, 'Activité'),
        ])->assertStatus(422);
    }

    public function test_update_info_rejects_activite_too_long(): void
    {
        Sanctum::actingAs($this->pasteur($this->church()));

        $this->putJson('/api/v1/about-church/info', [
            'activitesMajeures' => [str_repeat('a', ChurchAboutInfo::MAX_LONGUEUR_ACTIVITE + 1)],
        ])->assertStatus(422);
    }

    // =========================================================================
    // CREATE ARTICLE
    // =========================================================================

    public function test_create_article_without_images_succeeds(): void
    {
        $church = $this->church();
        Sanctum::actingAs($this->pasteur($church));

        $this->postJson('/api/v1/about-church/articles', [
            'type' => AboutChurchArticle::TYPE_GROUP,
            'titre' => 'Groupe des conseillers',
            'contenu' => 'Présentation du groupe des conseillers de la communauté.',
        ])->assertStatus(201)
          ->assertJsonPath('data.type', AboutChurchArticle::TYPE_GROUP)
          ->assertJsonPath('data.titre', 'Groupe des conseillers');

        $this->assertDatabaseHas('about_church_articles', [
            'churchId' => $church->id,
            'titre' => 'Groupe des conseillers',
        ]);
    }

    public function test_create_pastor_article_requires_statut_pasteur(): void
    {
        Sanctum::actingAs($this->pasteur($this->church()));

        $this->postJson('/api/v1/about-church/articles', [
            'type' => AboutChurchArticle::TYPE_PASTOR,
            'titre' => 'Pasteur Jean',
            'contenu' => 'Biographie suffisamment longue du pasteur.',
        ])->assertStatus(422);
    }

    public function test_create_article_validation_titre_too_short(): void
    {
        Sanctum::actingAs($this->pasteur($this->church()));

        $this->postJson('/api/v1/about-church/articles', [
            'type' => AboutChurchArticle::TYPE_ACTIVITY,
            'titre' => 'ab',
            'contenu' => 'Contenu suffisamment long pour passer la validation.',
        ])->assertStatus(422);
    }

    public function test_create_article_rejects_too_many_images(): void
    {
        Sanctum::actingAs($this->pasteur($this->church()));

        $images = array_map(fn ($i) => UploadedFile::fake()->image("photo{$i}.jpg"), range(1, AboutChurchArticle::MAX_IMAGES + 1));

        $this->post('/api/v1/about-church/articles', [
            'type' => AboutChurchArticle::TYPE_ACTIVITY,
            'titre' => 'Activité avec trop de photos',
            'contenu' => 'Contenu suffisamment long pour passer la validation.',
            'images' => $images,
        ])->assertStatus(422);
    }

    public function test_create_article_with_image_uploads_via_image_storage_service(): void
    {
        $this->mockImageUpload();
        Sanctum::actingAs($this->pasteur($this->church()));

        $this->post('/api/v1/about-church/articles', [
            'type' => AboutChurchArticle::TYPE_ACTIVITY,
            'titre' => 'Activité avec photo',
            'contenu' => 'Contenu suffisamment long pour passer la validation.',
            'images' => [UploadedFile::fake()->image('photo.jpg')],
        ])->assertStatus(201)
          ->assertJsonCount(1, 'data.images');
    }

    // =========================================================================
    // UPDATE / DELETE ARTICLE
    // =========================================================================

    public function test_update_without_touching_images_preserves_them(): void
    {
        $church = $this->church();
        $article = $this->article($church, overrides: [
            'images' => ['https://api.test/storage/about_church/a.jpg', 'https://api.test/storage/about_church/b.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->putJson("/api/v1/about-church/articles/{$article->uuid}", [
            'titre' => 'Titre modifié sans toucher aux photos',
        ])->assertStatus(200)
          ->assertJsonCount(2, 'data.images');
    }

    public function test_update_with_keep_images_drops_the_untouched_one(): void
    {
        $this->mockImageUpload();
        $church = $this->church();
        $article = $this->article($church, overrides: [
            'images' => ['https://api.test/storage/about_church/a.jpg', 'https://api.test/storage/about_church/b.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->put("/api/v1/about-church/articles/{$article->uuid}", [
            'photosTouched' => true,
            'keepImages' => ['https://api.test/storage/about_church/a.jpg'],
        ])->assertStatus(200)
          ->assertJsonCount(1, 'data.images')
          ->assertJsonPath('data.images.0', 'https://api.test/storage/about_church/a.jpg');
    }

    public function test_update_photos_touched_with_no_keep_images_removes_all(): void
    {
        $this->mockImageUpload();
        $church = $this->church();
        $article = $this->article($church, overrides: [
            'images' => ['https://api.test/storage/about_church/a.jpg', 'https://api.test/storage/about_church/b.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        // L'utilisateur a retiré toutes les photos existantes sans en
        // ajouter de nouvelle — cas qui a motivé le flag `photosTouched`
        // (un `keepImages` vide est indiscernable d'un champ absent en
        // multipart/form-data).
        $this->put("/api/v1/about-church/articles/{$article->uuid}", [
            'photosTouched' => true,
        ])->assertStatus(200)
          ->assertJsonCount(0, 'data.images');
    }

    public function test_update_with_keep_images_and_new_photo_combines_both(): void
    {
        $this->mockImageUpload();
        $church = $this->church();
        $article = $this->article($church, overrides: [
            'images' => ['https://api.test/storage/about_church/a.jpg', 'https://api.test/storage/about_church/b.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->put("/api/v1/about-church/articles/{$article->uuid}", [
            'photosTouched' => true,
            'keepImages' => ['https://api.test/storage/about_church/a.jpg'],
            'images' => [UploadedFile::fake()->image('nouvelle.jpg')],
        ])->assertStatus(200)
          ->assertJsonCount(2, 'data.images')
          ->assertJsonPath('data.images.0', 'https://api.test/storage/about_church/a.jpg');
    }

    public function test_update_rejects_keep_images_plus_new_over_max(): void
    {
        $church = $this->church();
        $article = $this->article($church, overrides: [
            'images' => ['https://api.test/storage/about_church/a.jpg', 'https://api.test/storage/about_church/b.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->put("/api/v1/about-church/articles/{$article->uuid}", [
            'keepImages' => ['https://api.test/storage/about_church/a.jpg', 'https://api.test/storage/about_church/b.jpg'],
            'images' => [UploadedFile::fake()->image('un.jpg'), UploadedFile::fake()->image('deux.jpg')],
        ])->assertStatus(422);
    }

    public function test_update_keep_images_ignores_url_not_belonging_to_article(): void
    {
        $church = $this->church();
        $article = $this->article($church, overrides: [
            'images' => ['https://api.test/storage/about_church/a.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->put("/api/v1/about-church/articles/{$article->uuid}", [
            'photosTouched' => true,
            'keepImages' => ['https://api.test/storage/about_church/a.jpg', 'https://api.test/storage/about_church/not-mine.jpg'],
        ])->assertStatus(200)
          ->assertJsonCount(1, 'data.images');
    }

    public function test_secretaire_can_update_article_of_same_church(): void
    {
        $church = $this->church();
        $article = $this->article($church);
        Sanctum::actingAs($this->secretaire($church));

        $this->putJson("/api/v1/about-church/articles/{$article->uuid}", [
            'titre' => 'Titre modifié',
        ])->assertStatus(200)
          ->assertJsonPath('data.titre', 'Titre modifié');
    }

    public function test_update_article_cross_church_forbidden(): void
    {
        $church1 = $this->church();
        $church2 = $this->church();
        $article = $this->article($church1);

        Sanctum::actingAs($this->pasteur($church2));

        $this->putJson("/api/v1/about-church/articles/{$article->uuid}", [
            'titre' => 'Tentative de modification',
        ])->assertStatus(403);
    }

    public function test_update_unknown_article_returns_404(): void
    {
        Sanctum::actingAs($this->pasteur($this->church()));
        $this->putJson('/api/v1/about-church/articles/uuid-inexistant', ['titre' => 'Titre valide'])->assertStatus(404);
    }

    public function test_pasteur_can_delete_article_of_same_church(): void
    {
        $church = $this->church();
        $article = $this->article($church);
        Sanctum::actingAs($this->pasteur($church));

        $this->deleteJson("/api/v1/about-church/articles/{$article->uuid}")->assertStatus(200);
        $this->assertDatabaseMissing('about_church_articles', ['uuid' => $article->uuid]);
    }

    public function test_delete_article_cross_church_forbidden(): void
    {
        $church1 = $this->church();
        $church2 = $this->church();
        $article = $this->article($church1);

        Sanctum::actingAs($this->pasteur($church2));

        $this->deleteJson("/api/v1/about-church/articles/{$article->uuid}")->assertStatus(403);
        $this->assertDatabaseHas('about_church_articles', ['uuid' => $article->uuid]);
    }

    // =========================================================================
    // PIN / UNPIN
    // =========================================================================

    public function test_pin_requires_at_least_one_image(): void
    {
        $church = $this->church();
        $article = $this->article($church, AboutChurchArticle::TYPE_GROUP);
        Sanctum::actingAs($this->pasteur($church));

        $this->postJson("/api/v1/about-church/articles/{$article->uuid}/pin")->assertStatus(400);
    }

    public function test_pin_and_unpin_group_article_with_image(): void
    {
        $church = $this->church();
        $article = $this->article($church, AboutChurchArticle::TYPE_GROUP, [
            'images' => ['https://api.test/storage/about_church/existing.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->postJson("/api/v1/about-church/articles/{$article->uuid}/pin")
            ->assertStatus(200)
            ->assertJsonPath('data.isPinned', true);

        $this->postJson("/api/v1/about-church/articles/{$article->uuid}/unpin")
            ->assertStatus(200)
            ->assertJsonPath('data.isPinned', false);
    }

    public function test_pin_rejects_ancien_pasteur(): void
    {
        $church = $this->church();
        $article = $this->article($church, AboutChurchArticle::TYPE_PASTOR, [
            'images' => ['https://api.test/storage/about_church/existing.jpg'],
            'statutPasteur' => AboutChurchArticle::STATUT_PASTEUR_ANCIEN,
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->postJson("/api/v1/about-church/articles/{$article->uuid}/pin")->assertStatus(400);
    }

    public function test_pin_quota_enforced_per_category(): void
    {
        $church = $this->church();
        Sanctum::actingAs($this->pasteur($church));

        $articles = [];
        for ($i = 0; $i < AboutChurchArticle::MAX_PINNED_GROUPS + 1; $i++) {
            $articles[] = $this->article($church, AboutChurchArticle::TYPE_GROUP, [
                'images' => ['https://api.test/storage/about_church/existing.jpg'],
            ]);
        }

        foreach (array_slice($articles, 0, AboutChurchArticle::MAX_PINNED_GROUPS) as $a) {
            $this->postJson("/api/v1/about-church/articles/{$a->uuid}/pin")->assertStatus(200);
        }

        // Le quota est atteint : le suivant doit échouer.
        $this->postJson('/api/v1/about-church/articles/' . end($articles)->uuid . '/pin')->assertStatus(400);
    }

    public function test_history_type_is_never_pinnable(): void
    {
        $church = $this->church();
        $article = $this->article($church, AboutChurchArticle::TYPE_HISTORY, [
            'images' => ['https://api.test/storage/about_church/existing.jpg'],
        ]);
        Sanctum::actingAs($this->pasteur($church));

        $this->postJson("/api/v1/about-church/articles/{$article->uuid}/pin")->assertStatus(400);
    }
}
