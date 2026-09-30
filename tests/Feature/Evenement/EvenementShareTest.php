<?php

namespace Tests\Feature\Evenement;

use App\Models\Church;
use App\Models\Evenement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvenementShareTest extends TestCase
{
    use RefreshDatabase;

    private function evenement(Church $church, User $auteur, array $overrides = []): Evenement
    {
        return Evenement::create(array_merge([
            'uuid'        => Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Construction du temple',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ], $overrides));
    }

    public function test_share_page_shows_open_graph_tags_for_active_event(): void
    {
        $church = Church::factory()->create(['nom' => 'EEC MESSAMEDONGO']);
        $pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $evenement = $this->evenement($church, $pasteur, [
            'titre' => 'Journée de reboisement',
            'imageUrl' => 'https://api.signmecy.org/storage/evenements/evenement.jpg',
        ]);

        $response = $this->get("/evenements/{$evenement->uuid}");

        $response->assertStatus(200);
        $response->assertSee('og:title', false);
        $response->assertSee('Journée de reboisement');
        $response->assertSee('EEC MESSAMEDONGO');
        $response->assertSee('https://api.signmecy.org/storage/evenements/evenement.jpg', false);
        $response->assertSee(url("/evenements/{$evenement->uuid}"), false);
    }

    public function test_share_page_falls_back_to_logo_when_no_image(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $evenement = $this->evenement($church, $pasteur, ['imageUrl' => null]);

        $response = $this->get("/evenements/{$evenement->uuid}");

        $response->assertStatus(200);
        $response->assertSee(asset('images/eec-logo.jpg'), false);
    }

    public function test_share_page_hides_draft_event(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $evenement = $this->evenement($church, $pasteur, [
            'statut' => Evenement::STATUT_BROUILLON,
            'publishedAt' => null,
        ]);

        $response = $this->get("/evenements/{$evenement->uuid}");

        $response->assertStatus(200);
        $response->assertSee('Événement introuvable');
        $response->assertDontSee($evenement->titre);
    }

    public function test_share_page_handles_unknown_uuid(): void
    {
        $response = $this->get('/evenements/' . Str::uuid());

        $response->assertStatus(200);
        $response->assertSee('Événement introuvable');
    }

    public function test_assetlinks_json_is_served(): void
    {
        $response = $this->get('/.well-known/assetlinks.json');

        $response->assertStatus(200);
        $response->assertJsonPath('0.target.package_name', 'com.signmecy.mobile');
        $response->assertJsonPath('0.relation.0', 'delegate_permission/common.handle_all_urls');
        $this->assertNotEmpty($response->json('0.target.sha256_cert_fingerprints'));
    }

    public function test_apple_app_site_association_is_served(): void
    {
        $response = $this->get('/.well-known/apple-app-site-association');

        $response->assertStatus(200);
        $response->assertJsonPath('applinks.details.0.paths.0', '/evenements/*');
        $this->assertStringEndsWith('.com.signmecy.mobile', $response->json('applinks.details.0.appID'));
    }
}
