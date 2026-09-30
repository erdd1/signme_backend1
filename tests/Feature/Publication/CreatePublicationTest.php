<?php

namespace Tests\Feature\Publication;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreatePublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_type_is_required(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/publications', [
            'titre' => 'Titre',
            'contenu' => 'Contenu suffisamment long',
        ]);

        $response->assertStatus(422);
    }

    public function test_title_is_required(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/publications', [
            'type' => Publication::TYPE_ANNONCE,
            'contenu' => 'Contenu suffisamment long',
        ]);

        $response->assertStatus(422);
    }

    public function test_content_is_required(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/publications', [
            'type' => Publication::TYPE_ANNONCE,
            'titre' => 'Titre',
        ]);

        $response->assertStatus(422);
    }

    public function test_pasteur_can_create_annonce(): void
    {
        $church = Church::factory()->create();

        $pasteur = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $token = $pasteur->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/publications', [
            'type' => Publication::TYPE_ANNONCE,
            'titre' => 'Réunion dimanche',
            'contenu' => 'Tous les membres sont invités.',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('publications', [
            'titre' => 'Réunion dimanche',
            'churchId' => $church->id,
        ]);
    }

    public function test_meditation_requires_bible_reference(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'role' => 'pasteur',
            'churchId' => $church->id,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/publications', [
            'type' => Publication::TYPE_MEDITATION,
            'titre' => 'Foi',
            'contenu' => 'Contenu méditation suffisamment long',
        ]);

        $response->assertStatus(400);
    }

    public function test_admin_can_publish_for_another_church(): void
    {
        $church1 = Church::factory()->create();
        $church2 = Church::factory()->create();

        $admin = User::factory()->create([
            'role' => 'administrateur',
            'is_super_admin' => true,
            'churchId' => $church1->id,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/admin/publications', [
            'type' => Publication::TYPE_ANNONCE,
            'titre' => 'Publication admin',
            'contenu' => 'Contenu publication admin',
            'churchId' => $church2->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('publications', [
            'titre' => 'Publication admin',
            'churchId' => $church2->id,
        ]);
    }

    public function test_guest_cannot_create_publication(): void
    {
        $response = $this->postJson('/api/v1/publications', [
            'type' => Publication::TYPE_ANNONCE,
            'titre' => 'Titre',
            'contenu' => 'Contenu suffisamment long',
        ]);

        $response->assertStatus(401);
    }

    public function test_paroissien_cannot_create_publication(): void
    {
        $user = User::factory()->create([
            'role' => 'paroissien',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$token
        )->postJson('/api/v1/publications', [
            'type' => Publication::TYPE_ANNONCE,
            'titre' => 'Titre',
            'contenu' => 'Contenu suffisamment long',
        ]);

        $response->assertStatus(403);
    }

    public function test_secretaire_can_create_publication(): void
{
    $church = Church::factory()->create();

    $user = User::factory()->create([
        'role' => 'secretaire',
        'churchId' => $church->id,
    ]);

    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader(
        'Authorization',
        'Bearer '.$token
    )->postJson('/api/v1/publications', [
        'type' => Publication::TYPE_ANNONCE,
        'titre' => 'Annonce',
        'contenu' => 'Contenu suffisamment long',
    ]);

    $response->assertStatus(201);
}
}