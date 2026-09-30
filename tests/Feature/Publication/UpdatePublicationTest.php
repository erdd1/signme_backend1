<?php

namespace Tests\Feature\Publication;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpdatePublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_update_publication(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $publication = Publication::create([
            'churchId' => $church->id,
            'type' => 'annonce',
            'titre' => 'Ancien titre',
            'contenu' => 'Ancien contenu publication',
            'auteurId' => $pasteur->id,
            'auteurNom' => $pasteur->nom,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->putJson("/api/v1/publications/{$publication->id}", [
            'titre' => 'Nouveau titre',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('publications', [
            'id' => $publication->id,
            'titre' => 'Nouveau titre',
        ]);
    }

    public function test_admin_can_update_any_publication(): void
    {
        $church = Church::factory()->create();

        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);

        $author = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $publication = Publication::create([
            'churchId' => $church->id,
            'type' => 'annonce',
            'titre' => 'Titre',
            'contenu' => 'Contenu publication',
            'auteurId' => $author->id,
            'auteurNom' => $author->nom,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->putJson("/api/admin/publications/{$publication->id}", [
            'titre' => 'Titre admin',
        ]);

        $response->assertStatus(200);
    }

    public function test_non_author_cannot_update_publication(): void
    {
        $church = Church::factory()->create();

        $author = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $other = User::factory()->create([
            'role' => 'secretaire',
            'churchId' => $church->id,
        ]);

        $publication = Publication::create([
            'churchId' => $church->id,
            'type' => 'annonce',
            'titre' => 'Titre',
            'contenu' => 'Contenu publication',
            'auteurId' => $author->id,
            'auteurNom' => $author->nom,
        ]);

        $token = $other->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->putJson("/api/v1/publications/{$publication->id}", [
            'titre' => 'Piratage',
        ]);

        $response->assertStatus(403);
    }

    public function test_title_must_have_minimum_length(): void
    {
        $church = Church::factory()->create();

        $author = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $publication = Publication::create([
            'churchId' => $church->id,
            'type' => 'annonce',
            'titre' => 'Titre',
            'contenu' => 'Contenu publication',
            'auteurId' => $author->id,
            'auteurNom' => $author->nom,
        ]);

        $token = $author->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->putJson("/api/v1/publications/{$publication->id}", [
            'titre' => 'ab',
        ]);

        $response->assertStatus(422);
    }

    public function test_content_must_have_minimum_length(): void
    {
        $church = Church::factory()->create();

        $author = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $publication = Publication::create([
            'churchId' => $church->id,
            'type' => 'annonce',
            'titre' => 'Titre',
            'contenu' => 'Contenu publication',
            'auteurId' => $author->id,
            'auteurNom' => $author->nom,
        ]);

        $token = $author->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->putJson("/api/v1/publications/{$publication->id}", [
            'contenu' => 'court',
        ]);

        $response->assertStatus(422);
    }

    public function test_reference_biblique_can_be_updated(): void
    {
        $church = Church::factory()->create();

        $author = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $publication = Publication::create([
            'churchId' => $church->id,
            'type' => 'meditation',
            'titre' => 'Titre',
            'contenu' => 'Contenu méditation',
            'referenceBiblique' => 'Jean 3:16',
            'auteurId' => $author->id,
            'auteurNom' => $author->nom,
        ]);

        $token = $author->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->putJson("/api/v1/publications/{$publication->id}", [
            'referenceBiblique' => 'Psaume 23',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('publications', [
            'id' => $publication->id,
            'referenceBiblique' => 'Psaume 23',
        ]);
    }

    public function test_guest_cannot_update_publication(): void
    {
        $church = Church::factory()->create();

        $author = User::factory()->create([
            'churchId' => $church->id,
        ]);

        $publication = Publication::create([
            'churchId' => $church->id,
            'type' => 'annonce',
            'titre' => 'Titre',
            'contenu' => 'Contenu publication',
            'auteurId' => $author->id,
            'auteurNom' => $author->nom,
        ]);

        $response = $this->putJson(
            "/api/v1/publications/{$publication->id}",
            ['titre' => 'Test']
        );

        $response->assertStatus(401);
    }
}