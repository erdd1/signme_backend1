<?php

namespace Tests\Feature\Badge;

use Tests\TestCase;
use App\Models\Church;
use App\Models\Evenement;
use App\Models\Publication;
use App\Models\PublicationRead;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * BadgeTest — Tests Feature pour GET /api/v1/notifications/badges
 *                          et POST /api/v1/notifications/badges/mark-read
 *
 * Couvre :
 *  - Authentification (401 sans token)
 *  - Structure de réponse JSON
 *  - Badge publications (création, lecture, reset)
 *  - Badge historique personnel (création, reset)
 *  - Badge historique signatures (ancien/pasteur only)
 *  - Validation du type mark-read
 *  - mark-read retourne les nouveaux compteurs
 */
class BadgeTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers privés
    // =========================================================================

    private function makeChurch(): Church
    {
        return Church::factory()->create();
    }

    private function makeUser(string $role, ?int $churchId = null): User
    {
        return User::factory()->create([
            'role'     => $role,
            'churchId' => $churchId,
            'actif'    => true,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function getBadges(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->getJson('/api/v1/notifications/badges');
    }

    private function markRead(User $user, string $type)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->postJson('/api/v1/notifications/badges/mark-read', [
            'type' => $type,
        ]);
    }

    private function createPublication(User $author, Church $church, array $overrides = []): Publication
    {
        return Publication::create(array_merge([
            'churchId'       => $church->id,
            'type'           => Publication::TYPE_ANNONCE,
            'titre'          => 'Test publication',
            'contenu'        => 'Contenu de test suffisamment long',
            'auteurId'       => $author->id,
            'auteurNom'      => $author->nom,
            'auteurPhotoUrl' => null,
            'date'           => now(),
        ], $overrides));
    }

    private function createEvenement(User $auteur, Church $church, array $overrides = []): Evenement
    {
        return Evenement::create(array_merge([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Test événement',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ], $overrides));
    }

    private function createSignature(User $user, User $ancien, string $status = 'completed'): Signature
    {
        return Signature::create([
            'userId'        => $user->id,
            'nomParoissien' => $user->nom,
            'ancienId'      => $ancien->id,
            'ancienNom'     => $ancien->nom,
            'churchId'      => $user->churchId ?? $ancien->churchId,
            'status'        => $status,
            'month'         => now()->format('Y-m'),
            'monthIndex'    => (int) now()->format('n') - 1,
            'year'          => (int) now()->format('Y'),
            'amount'        => 1000,
            'fees'          => 0,
            'signatureType' => Signature::TYPE_PRESENTIEL,
        ]);
    }

    // =========================================================================
    // Tests — Authentification & Structure
    // =========================================================================

    public function test_unauthenticated_user_cannot_access_badges(): void
    {
        $response = $this->getJson('/api/v1/notifications/badges');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_mark_read(): void
    {
        $response = $this->postJson('/api/v1/notifications/badges/mark-read', [
            'type' => 'annonces',
        ]);

        $response->assertStatus(401);
    }

    public function test_badges_response_has_correct_structure(): void
    {
        $church = $this->makeChurch();
        $user   = $this->makeUser('paroissien', $church->id);

        $response = $this->getBadges($user);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'annonces',
                    'meditations',
                    'evenements',
                    'historique',
                    'historique_signatures',
                ],
            ])
            ->assertJsonPath('success', true);
    }

    public function test_all_badges_are_zero_for_new_user_with_no_data(): void
    {
        $church = $this->makeChurch();
        $user   = $this->makeUser('paroissien', $church->id);

        $response = $this->getBadges($user);

        $response->assertStatus(200)
            ->assertJsonPath('data.annonces', 0)
            ->assertJsonPath('data.meditations', 0)
            ->assertJsonPath('data.evenements', 0)
            ->assertJsonPath('data.historique', 0)
            ->assertJsonPath('data.historique_signatures', 0);
    }

    // =========================================================================
    // Tests — Badge Publications (Annonces / Méditations)
    // =========================================================================

    public function test_publication_badge_increases_when_publication_exists(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        // Le pasteur crée une annonce et une méditation (sans passer par l'API
        // pour isoler la logique badge)
        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_ANNONCE]);
        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_MEDITATION]);

        // Le member n'a rien lu → badge = 1 pour chaque type, indépendamment
        $response = $this->getBadges($member);

        $response->assertStatus(200)
            ->assertJsonPath('data.annonces', 1)
            ->assertJsonPath('data.meditations', 1);
    }

    public function test_author_does_not_see_badge_for_own_publication(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);

        // Créer une pub et marquer l'auteur comme lecteur (comme le ferait PublicationService)
        $pub = $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_ANNONCE]);
        PublicationRead::create([
            'userId'        => $pasteur->id,
            'publicationId' => $pub->id,
            'readAt'        => now(),
        ]);

        // L'auteur ne doit pas voir son propre badge
        $response = $this->getBadges($pasteur);

        $response->assertStatus(200)
            ->assertJsonPath('data.annonces', 0);
    }

    public function test_mark_annonces_as_read_resets_only_annonces_badge(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_ANNONCE]);
        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_MEDITATION]);

        // Les deux badges sont à 1
        $this->getBadges($member)
            ->assertJsonPath('data.annonces', 1)
            ->assertJsonPath('data.meditations', 1);

        // Marquer uniquement les annonces comme lues
        $response = $this->markRead($member, 'annonces');

        $response->assertStatus(200)
            ->assertJsonPath('data.annonces', 0)
            // Le badge méditations reste intact — les deux types sont indépendants
            ->assertJsonPath('data.meditations', 1);

        $this->assertDatabaseHas('publication_reads', [
            'userId' => $member->id,
        ]);
    }

    public function test_mark_meditations_as_read_resets_only_meditations_badge(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_ANNONCE]);
        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_MEDITATION]);

        $response = $this->markRead($member, 'meditations');

        $response->assertStatus(200)
            ->assertJsonPath('data.meditations', 0)
            ->assertJsonPath('data.annonces', 1);
    }

    public function test_mark_read_response_contains_updated_counts(): void
    {
        $church = $this->makeChurch();
        $member = $this->makeUser('paroissien', $church->id);

        // Aucune publication → mark-read doit renvoyer 0 pour tout
        $response = $this->markRead($member, 'annonces');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['annonces', 'meditations', 'evenements', 'historique', 'historique_signatures']]);
    }

    public function test_multiple_publications_all_count_as_unread(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_ANNONCE]);
        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_ANNONCE, 'titre' => 'Deuxième annonce']);
        $this->createPublication($pasteur, $church, ['type' => Publication::TYPE_ANNONCE, 'titre' => 'Troisième annonce']);

        $response = $this->getBadges($member);

        $response->assertJsonPath('data.annonces', 3);
    }

    public function test_publication_badge_only_counts_own_church_publications(): void
    {
        $church1 = $this->makeChurch();
        $church2 = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church1->id);
        $member  = $this->makeUser('paroissien', $church2->id);

        // Publication dans l'église 1, membre dans l'église 2
        $this->createPublication($pasteur, $church1, ['type' => Publication::TYPE_ANNONCE]);

        $response = $this->getBadges($member);

        $response->assertJsonPath('data.annonces', 0);
    }

    // =========================================================================
    // Tests — Badge Événements
    // =========================================================================

    public function test_evenement_badge_increases_when_evenement_exists(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        $this->createEvenement($pasteur, $church);

        $response = $this->getBadges($member);

        $response->assertStatus(200)
            ->assertJsonPath('data.evenements', 1);
    }

    public function test_author_does_not_see_badge_for_own_evenement(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);

        $this->createEvenement($pasteur, $church);

        $response = $this->getBadges($pasteur);

        $response->assertStatus(200)
            ->assertJsonPath('data.evenements', 0);
    }

    public function test_mark_evenements_as_read_resets_badge_to_zero(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        $this->createEvenement($pasteur, $church);

        $this->getBadges($member)->assertJsonPath('data.evenements', 1);

        $response = $this->markRead($member, 'evenements');

        $response->assertStatus(200)
            ->assertJsonPath('data.evenements', 0);

        $member->refresh();
        $this->assertNotNull($member->last_read_evenements_at);
    }

    public function test_evenement_badge_only_counts_own_church_evenements(): void
    {
        $church1 = $this->makeChurch();
        $church2 = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church1->id);
        $member  = $this->makeUser('paroissien', $church2->id);

        $this->createEvenement($pasteur, $church1);

        $response = $this->getBadges($member);

        $response->assertJsonPath('data.evenements', 0);
    }

    public function test_draft_evenement_does_not_count_for_badge(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        $this->createEvenement($pasteur, $church, [
            'statut'      => Evenement::STATUT_BROUILLON,
            'publishedAt' => null,
        ]);

        $response = $this->getBadges($member);

        $response->assertJsonPath('data.evenements', 0);
    }

    // =========================================================================
    // Tests — Badge Historique Personnel
    // =========================================================================

    public function test_historique_badge_is_zero_with_no_signatures(): void
    {
        $church = $this->makeChurch();
        $user   = $this->makeUser('paroissien', $church->id);

        $response = $this->getBadges($user);

        $response->assertJsonPath('data.historique', 0);
    }

    public function test_historique_badge_increases_with_completed_signature(): void
    {
        $church  = $this->makeChurch();
        $ancien  = $this->makeUser('ancien', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        // On met la signature dans la base directement
        $member->update(['churchId' => $church->id]);
        $this->createSignature($member, $ancien, 'completed');

        $response = $this->getBadges($member);

        $response->assertJsonPath('data.historique', 1);
    }

    public function test_pending_signature_does_not_count_for_historique_badge(): void
    {
        $church = $this->makeChurch();
        $ancien = $this->makeUser('ancien', $church->id);
        $member = $this->makeUser('paroissien', $church->id);

        $this->createSignature($member, $ancien, 'pending');

        $response = $this->getBadges($member);

        // pending ne compte pas
        $response->assertJsonPath('data.historique', 0);
    }

    public function test_mark_historique_as_read_resets_badge(): void
    {
        $church = $this->makeChurch();
        $ancien = $this->makeUser('ancien', $church->id);
        $member = $this->makeUser('paroissien', $church->id);

        $this->createSignature($member, $ancien, 'completed');

        // Badge est à 1
        $this->getBadges($member)->assertJsonPath('data.historique', 1);

        // Marquer comme lu
        $response = $this->markRead($member, 'historique');

        $response->assertStatus(200)
            ->assertJsonPath('data.historique', 0);

        // Le timestamp doit être en base
        $member->refresh();
        $this->assertNotNull($member->last_read_historique_at);
    }

    // =========================================================================
    // Tests — Badge Historique Signatures (Ancien/Pasteur)
    // =========================================================================

    public function test_paroissien_always_gets_zero_for_historique_signatures(): void
    {
        $church  = $this->makeChurch();
        $ancien  = $this->makeUser('ancien', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        // L'ancien signe pour le membre
        $this->createSignature($member, $ancien, 'completed');

        // Le paroissien ne doit PAS voir ce badge
        $response = $this->getBadges($member);

        $response->assertJsonPath('data.historique_signatures', 0);
    }

    public function test_ancien_sees_badge_for_signatures_he_performed(): void
    {
        $church = $this->makeChurch();
        $ancien = $this->makeUser('ancien', $church->id);
        $member = $this->makeUser('paroissien', $church->id);

        // L'ancien effectue une signature
        $this->createSignature($member, $ancien, 'completed');

        $response = $this->getBadges($ancien);

        $response->assertJsonPath('data.historique_signatures', 1);
    }

    public function test_pasteur_sees_badge_for_signatures_he_performed(): void
    {
        $church  = $this->makeChurch();
        $pasteur = $this->makeUser('pasteur', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        $this->createSignature($member, $pasteur, 'completed');

        $response = $this->getBadges($pasteur);

        $response->assertJsonPath('data.historique_signatures', 1);
    }

    public function test_mark_historique_signatures_as_read_resets_badge(): void
    {
        $church = $this->makeChurch();
        $ancien = $this->makeUser('ancien', $church->id);
        $member = $this->makeUser('paroissien', $church->id);

        $this->createSignature($member, $ancien, 'completed');

        // Badge est à 1
        $this->getBadges($ancien)->assertJsonPath('data.historique_signatures', 1);

        // Marquer comme lu
        $response = $this->markRead($ancien, 'historique_signatures');

        $response->assertStatus(200)
            ->assertJsonPath('data.historique_signatures', 0);

        // Vérifier le timestamp en base
        $ancien->refresh();
        $this->assertNotNull($ancien->last_read_historique_signatures_at);
    }

    public function test_ancien_only_sees_his_own_signatures_badge(): void
    {
        $church  = $this->makeChurch();
        $ancien1 = $this->makeUser('ancien', $church->id);
        $ancien2 = $this->makeUser('ancien', $church->id);
        $member  = $this->makeUser('paroissien', $church->id);

        // Ancien1 signe pour le membre
        $this->createSignature($member, $ancien1, 'completed');

        // Ancien2 ne doit pas voir le badge de la signature d'Ancien1
        $response = $this->getBadges($ancien2);

        $response->assertJsonPath('data.historique_signatures', 0);
    }

    // =========================================================================
    // Tests — Validation mark-read
    // =========================================================================

    public function test_mark_read_rejects_missing_type(): void
    {
        $church = $this->makeChurch();
        $user   = $this->makeUser('paroissien', $church->id);

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->postJson('/api/v1/notifications/badges/mark-read', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_mark_read_rejects_invalid_type(): void
    {
        $church = $this->makeChurch();
        $user   = $this->makeUser('paroissien', $church->id);

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $this->token($user)
        )->postJson('/api/v1/notifications/badges/mark-read', [
            'type' => 'type_invalide',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_mark_read_accepts_all_valid_types(): void
    {
        $church = $this->makeChurch();
        $ancien = $this->makeUser('ancien', $church->id);

        foreach (['annonces', 'meditations', 'evenements', 'historique', 'historique_signatures'] as $type) {
            $response = $this->markRead($ancien, $type);
            $response->assertStatus(200);
        }
    }
}
