<?php

namespace Tests\Feature\Publication;

use Tests\TestCase;
use App\Models\User;
use App\Models\Church;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CommuniantAccessTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_NOT_COMMUNIANT =
        "Vous ne pouvez pas avoir accès à cette fonctionnalité car vous n'êtes pas communiant.";

    private function makePublication(int $churchId, User $auteur, string $type, array $overrides = []): Publication
    {
        return Publication::create(array_merge([
            'churchId' => $churchId,
            'type' => $type,
            'titre' => $type === Publication::TYPE_MEDITATION ? 'Méditation test' : 'Annonce test',
            'contenu' => 'Contenu de test suffisamment long.',
            'referenceBiblique' => $type === Publication::TYPE_MEDITATION ? 'Jean 3:16' : null,
            'auteurId' => $auteur->id,
            'auteurNom' => $auteur->nom,
            'date' => now(),
        ], $overrides));
    }

    public function test_non_communiant_paroissien_list_excludes_meditations(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $this->makePublication($church->id, $pasteur, Publication::TYPE_ANNONCE, ['titre' => 'Annonce visible']);
        $this->makePublication($church->id, $pasteur, Publication::TYPE_MEDITATION, ['titre' => 'Méditation cachée']);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/publications');

        $response->assertStatus(200);
        $titres = collect($response->json('data'))->pluck('titre');
        $this->assertTrue($titres->contains('Annonce visible'));
        $this->assertFalse($titres->contains('Méditation cachée'));
    }

    public function test_communiant_paroissien_list_includes_meditations(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => true,
        ]);

        $this->makePublication($church->id, $pasteur, Publication::TYPE_MEDITATION, ['titre' => 'Méditation visible']);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/publications');

        $response->assertStatus(200);
        $titres = collect($response->json('data'))->pluck('titre');
        $this->assertTrue($titres->contains('Méditation visible'));
    }

    public function test_non_communiant_paroissien_cannot_filter_meditation_type(): void
    {
        $church = Church::factory()->create();
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/publications?type=meditation');

        $response->assertStatus(403);
        $response->assertJsonPath('message', self::MSG_NOT_COMMUNIANT);
    }

    public function test_non_communiant_paroissien_cannot_view_meditation_detail(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $meditation = $this->makePublication($church->id, $pasteur, Publication::TYPE_MEDITATION);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/publications/{$meditation->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('message', self::MSG_NOT_COMMUNIANT);
    }

    public function test_non_communiant_paroissien_can_view_annonce_detail(): void
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => User::ROLE_PASTEUR, 'churchId' => $church->id]);
        $paroissien = User::factory()->create([
            'role' => User::ROLE_PAROISSIEN,
            'churchId' => $church->id,
            'estCommuniant' => false,
        ]);

        $annonce = $this->makePublication($church->id, $pasteur, Publication::TYPE_ANNONCE);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/publications/{$annonce->id}");

        $response->assertStatus(200);
    }
}
