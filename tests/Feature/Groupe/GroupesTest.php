<?php

namespace Tests\Feature\Groupe;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\GroupeCommunique;
use App\Models\Retrait;
use App\Models\RetraitBeneficiaire;
use App\Models\User;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Groupe\Application\Jobs\SendGroupePushJob;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Tresorerie\Application\Services\SortieFinanciereService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Service « Groupes » : postes (président, trésorier, simple membre),
 * communiqués, contributions et dons au groupe, caisse (en ligne + cash),
 * retraits — et étanchéité totale avec l'argent de l'église.
 */
class GroupesTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;
    private Groupe $groupe;
    private User $president;
    private User $tresorier;
    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        config([
            'payment.card.enabled'            => true,
            'payment.card.currency'           => 'XAF',
            'payment.card.fee_percent'        => 5.0,
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-xyz',
        ]);

        $this->church = Church::factory()->create();
        $this->groupe = Groupe::factory()->create(['churchId' => $this->church->id, 'nom' => 'Cojeunesse']);

        $this->president = $this->chretien();
        $this->tresorier = $this->chretien();
        $this->membre    = $this->chretien();

        $this->groupe->membres()->attach($this->president->id, ['estPresident' => true]);
        $this->groupe->membres()->attach($this->tresorier->id, ['estTresorier' => true]);
        $this->groupe->membres()->attach($this->membre->id);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function chretien(string $role = 'paroissien'): User
    {
        return User::factory()->create(['role' => $role, 'churchId' => $this->church->id, 'actif' => true]);
    }

    private function base(): string
    {
        return "/api/v1/mes-groupes/{$this->groupe->uuid}";
    }

    /** Contribution de groupe complétée (argent reçu par l'application). */
    private function contributionComplete(User $donateur, int $montant, ?GroupeCommunique $communique = null): Contribution
    {
        return Contribution::create([
            'uuid' => (string) Str::uuid(), 'evenementId' => null,
            'groupeCollecteId' => $this->groupe->id, 'communiqueId' => $communique?->id,
            'userId' => $donateur->id, 'montant' => $montant, 'frais' => 50, 'montantTotal' => $montant + 50,
            'paymentMethod' => 'card', 'paymentGateway' => 'flutterwave',
            'payment_reference' => 'SIGNME-' . Str::uuid(), 'statut' => Contribution::STATUT_COMPLETED,
        ]);
    }

    private function communique(array $extra = []): GroupeCommunique
    {
        return $this->groupe->communiques()->create($extra + [
            'auteurId' => $this->president->id, 'titre' => 'Dons de reconnaissance', 'message' => 'Participez !',
            'objectifMontant' => 100000,
        ]);
    }

    private function beneficiaire(User $user): RetraitBeneficiaire
    {
        return RetraitBeneficiaire::create([
            'userId' => $user->id, 'churchId' => $user->churchId, 'channel' => RetraitBeneficiaire::CHANNEL_ORANGE,
            'account_number' => '237699000001', 'name' => $user->nom,
            'notchpay_beneficiary_id' => 'ben_existing', 'is_active' => true,
        ]);
    }

    // ─── Postes à la création / modification d'un chrétien ───────────────────

    public function test_pasteur_assigns_postes_when_creating_a_christian(): void
    {
        $pasteur = $this->chretien('pasteur');
        $autre = Groupe::factory()->create(['churchId' => $this->church->id]);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/users', [
            'nom' => 'Paul Nouveau', 'email' => 'paul@test.com', 'password' => 'Password123',
            'role' => 'paroissien', 'estCommuniant' => true, 'sexe' => 'homme', 'matricule' => 'H001',
            'groupes' => [
                ['id' => $this->groupe->id, 'postes' => ['president', 'tresorier']],
                ['id' => $autre->id, 'postes' => []],
            ],
        ])->assertStatus(201)
          ->assertJsonPath('data.groupes.0.postes', ['president', 'tresorier'])
          ->assertJsonPath('data.groupes.1.postes', []);

        $paul = User::where('email', 'paul@test.com')->first();
        $this->assertTrue((bool) $paul->groupes->firstWhere('id', $this->groupe->id)->pivot->estPresident);
        $this->assertTrue((bool) $paul->groupes->firstWhere('id', $this->groupe->id)->pivot->estTresorier);
    }

    public function test_a_new_president_demotes_the_previous_one_to_simple_member(): void
    {
        $pasteur = $this->chretien('pasteur');
        Sanctum::actingAs($pasteur);

        $this->putJson("/api/v1/users/{$this->membre->id}", [
            'groupes' => [['id' => $this->groupe->id, 'postes' => ['president']]],
        ])->assertOk();

        $this->assertSame(1, \DB::table('groupe_user')->where('groupeId', $this->groupe->id)->where('estPresident', true)->count());
        $this->assertTrue((bool) $this->membre->fresh()->groupes->first()->pivot->estPresident);
        $ancien = $this->president->fresh()->groupes->first()->pivot;
        $this->assertFalse((bool) $ancien->estPresident);
        $this->assertFalse((bool) $ancien->estTresorier);
        $this->assertTrue((bool) $this->tresorier->fresh()->groupes->first()->pivot->estTresorier); // inchangé
    }

    public function test_legacy_group_ids_keep_existing_postes(): void
    {
        $pasteur = $this->chretien('pasteur');
        Sanctum::actingAs($pasteur);

        // Ancienne version de l'app : envoie seulement groupeIds (+ un groupePrincipalId ignoré).
        $this->putJson("/api/v1/users/{$this->president->id}", [
            'groupeIds' => [$this->groupe->id], 'groupePrincipalId' => $this->groupe->id,
        ])->assertOk();

        $this->assertTrue((bool) $this->president->fresh()->groupes->first()->pivot->estPresident);
    }

    public function test_invalid_poste_is_refused(): void
    {
        Sanctum::actingAs($this->chretien('pasteur'));

        $this->putJson("/api/v1/users/{$this->membre->id}", [
            'groupes' => [['id' => $this->groupe->id, 'postes' => ['roi']]],
        ])->assertStatus(422);
    }

    public function test_postes_are_independent_from_the_role_tresorier_of_the_church(): void
    {
        $tresorierEglise = $this->chretien('tresorier'); // rôle église, sans poste de groupe
        Sanctum::actingAs($tresorierEglise);

        $this->getJson('/api/v1/mes-groupes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->base())->assertStatus(404);
        $this->postJson($this->base() . '/entrees-cash', ['montant' => 1000])->assertStatus(404);
    }

    // ─── Accès : seuls les membres ───────────────────────────────────────────

    public function test_member_lists_only_his_groups_with_his_postes(): void
    {
        Groupe::factory()->create(['churchId' => $this->church->id, 'nom' => 'Autre']);
        Sanctum::actingAs($this->president);

        $this->getJson('/api/v1/mes-groupes')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nom', 'Cojeunesse')
            ->assertJsonPath('data.0.postes', ['president']);
    }

    public function test_pasteur_not_member_sees_nothing_of_the_group(): void
    {
        Sanctum::actingAs($this->chretien('pasteur'));

        $this->getJson('/api/v1/mes-groupes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->base())->assertStatus(404);
        $this->getJson($this->base() . '/communiques')->assertStatus(404);
        $this->getJson($this->base() . '/mouvements')->assertStatus(404);
    }

    public function test_archived_group_disappears_for_members(): void
    {
        $this->groupe->update(['archivedAt' => now()]);
        Sanctum::actingAs($this->membre);

        $this->getJson('/api/v1/mes-groupes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->base())->assertStatus(404);
    }

    public function test_malformed_uuid_is_a_clean_404(): void
    {
        Sanctum::actingAs($this->membre);

        $this->getJson('/api/v1/mes-groupes/pas-un-uuid')->assertStatus(404);
    }

    // ─── Communiqués ─────────────────────────────────────────────────────────

    public function test_only_the_president_publishes_and_members_are_notified(): void
    {
        Sanctum::actingAs($this->president);

        $this->postJson($this->base() . '/communiques', [
            'titre' => 'Dons de reconnaissance', 'message' => 'Les dons sont lancés.', 'objectifMontant' => 50000,
        ])->assertStatus(201)->assertJsonPath('data.titre', 'Dons de reconnaissance');

        Queue::assertPushed(SendGroupePushJob::class, fn ($j) => $j->groupeId === $this->groupe->id
            && $j->excludeUserId === $this->president->id);

        foreach ([$this->tresorier, $this->membre] as $autre) {
            Sanctum::actingAs($autre);
            $this->postJson($this->base() . '/communiques', ['titre' => 'x', 'message' => 'y'])->assertStatus(403);
        }
        $this->assertSame(1, GroupeCommunique::count());
    }

    public function test_members_read_communiques_with_progress(): void
    {
        $c = $this->communique();
        $this->contributionComplete($this->membre, 2000, $c);
        $this->contributionComplete($this->tresorier, 3000, $c);
        Sanctum::actingAs($this->membre);

        $this->getJson($this->base() . '/communiques')->assertOk()
            ->assertJsonPath('data.0.montantCollecte', 5000)
            ->assertJsonPath('data.0.nombreContributions', 2)
            ->assertJsonPath('data.0.objectifMontant', 100000);
    }

    public function test_president_edits_and_archives_a_communique_without_losing_contributions(): void
    {
        $c = $this->communique();
        $contribution = $this->contributionComplete($this->membre, 2000, $c);
        Sanctum::actingAs($this->president);

        $this->putJson($this->base() . "/communiques/{$c->uuid}", ['titre' => 'Nouveau titre'])
            ->assertOk()->assertJsonPath('data.titre', 'Nouveau titre')
            ->assertJsonPath('data.montantCollecte', 2000);

        $this->deleteJson($this->base() . "/communiques/{$c->uuid}")->assertOk();

        $this->assertNotNull($c->fresh()->archivedAt);
        $this->assertDatabaseHas('groupe_communiques', ['id' => $c->id]); // jamais supprimé en base
        $this->assertDatabaseHas('contributions', ['id' => $contribution->id]);
        $this->getJson($this->base() . '/communiques')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_everyone_sees_who_contributed_to_a_communique(): void
    {
        $c = $this->communique();
        $this->contributionComplete($this->membre, 2000, $c);
        Sanctum::actingAs($this->tresorier);

        $this->getJson($this->base() . "/communiques/{$c->uuid}/contributions")->assertOk()
            ->assertJsonPath('data.0.nom', $this->membre->nom)
            ->assertJsonPath('data.0.montant', 2000);
    }

    // ─── Contributions et dons au groupe ─────────────────────────────────────

    public function test_member_contributes_by_card_to_a_communique_without_touching_the_church(): void
    {
        $c = $this->communique();
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response([
            'status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/g1'],
        ])]);
        Sanctum::actingAs($this->membre);

        $this->postJson($this->base() . '/contributions', [
            'montant' => 1000, 'paymentMethod' => 'card', 'communiqueUuid' => $c->uuid,
        ])->assertStatus(201)
          ->assertJsonPath('data.montantTotal', 1053)
          ->assertJsonPath('data.checkoutUrl', 'https://checkout.flutterwave.test/pay/g1');

        $contribution = Contribution::sole();
        $this->assertNull($contribution->evenementId);
        $this->assertSame($this->groupe->id, $contribution->groupeCollecteId);
        $this->assertSame($c->id, $contribution->communiqueId);
        $this->assertNull($contribution->groupeId); // groupe du contributeur : sans rapport
    }

    public function test_free_donation_to_the_group_needs_no_communique(): void
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response([
            'status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/g2'],
        ])]);
        Sanctum::actingAs($this->membre);

        $this->postJson($this->base() . '/contributions', ['montant' => 1000, 'paymentMethod' => 'card'])
            ->assertStatus(201);

        $this->assertNull(Contribution::sole()->communiqueId);
    }

    public function test_cannot_contribute_to_a_group_one_does_not_belong_to(): void
    {
        $etranger = $this->chretien();
        Http::fake();
        Sanctum::actingAs($etranger);

        $this->postJson($this->base() . '/contributions', ['montant' => 1000, 'paymentMethod' => 'card'])->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_cannot_contribute_to_an_archived_communique(): void
    {
        $c = $this->communique(['archivedAt' => now()]);
        Http::fake();
        Sanctum::actingAs($this->membre);

        $this->postJson($this->base() . '/contributions', [
            'montant' => 1000, 'paymentMethod' => 'card', 'communiqueUuid' => $c->uuid,
        ])->assertStatus(404);
    }

    public function test_confirmed_group_payment_credits_the_group_cash_register_only(): void
    {
        $c = $this->communique();
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response([
            'status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/g3'],
        ])]);
        Sanctum::actingAs($this->membre);
        $this->postJson($this->base() . '/contributions', [
            'montant' => 1000, 'paymentMethod' => 'card', 'communiqueUuid' => $c->uuid,
        ])->assertStatus(201);
        $contribution = Contribution::sole();

        app(ContributionService::class)->processPaymentNotification([
            'reference' => $contribution->payment_reference, 'status' => 'successful', 'amount' => 1053, 'currency' => 'XAF',
        ]);

        $this->assertSame(Contribution::STATUT_COMPLETED, $contribution->fresh()->statut);
        $this->getJson($this->base())->assertOk()
            ->assertJsonPath('data.caisse.enLigne', 1000)
            ->assertJsonPath('data.caisse.total', 1000);
    }

    // ─── Étanchéité avec la trésorerie de l'église ───────────────────────────

    public function test_group_money_never_enters_the_church_treasury(): void
    {
        $pasteur = $this->chretien('pasteur');
        $this->contributionComplete($this->membre, 5000);

        $stats = app(SortieFinanciereService::class)->getDashboardStats($pasteur);

        $this->assertSame(0, (int) $stats['global']['solde_actuel']);

        Sanctum::actingAs($pasteur);
        $this->getJson('/api/v1/retraits/solde')->assertOk()->assertJsonPath('data.solde', 0);
        $this->getJson('/api/v1/contributions-evenements')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/contributions-evenements/stats')->assertOk()->assertJsonPath('data.total_paye', 0);
    }

    // ─── Caisse, cash, historique ────────────────────────────────────────────

    public function test_caisse_separates_online_cash_and_withdrawals_and_all_members_see_it(): void
    {
        $this->contributionComplete($this->membre, 10000);
        Sanctum::actingAs($this->tresorier);
        $this->postJson($this->base() . '/entrees-cash', [
            'montant' => 4000, 'donateur' => 'Le parrain', 'note' => 'Pour les activités',
        ])->assertStatus(201)->assertJsonPath('data.caisse.cash', 4000);

        Sanctum::actingAs($this->membre);
        $this->getJson($this->base())->assertOk()
            ->assertJsonPath('data.caisse.enLigne', 10000)
            ->assertJsonPath('data.caisse.cash', 4000)
            ->assertJsonPath('data.caisse.retraits', 0)
            ->assertJsonPath('data.caisse.soldeEnLigne', 10000)
            ->assertJsonPath('data.caisse.total', 14000)
            ->assertJsonPath('data.responsables.president', $this->president->nom)
            ->assertJsonPath('data.responsables.tresorier', $this->tresorier->nom);
    }

    public function test_simple_member_cannot_record_cash_or_withdraw(): void
    {
        Sanctum::actingAs($this->membre);

        $this->postJson($this->base() . '/entrees-cash', ['montant' => 1000])->assertStatus(403);
        $this->postJson($this->base() . '/retraits', ['montant' => 1000, 'motif' => 'x'])->assertStatus(403);
        $this->getJson($this->base() . '/retraits/beneficiaire')->assertStatus(403);
    }

    public function test_cash_entry_is_cancelled_with_a_trace_never_deleted_or_edited(): void
    {
        Sanctum::actingAs($this->president);
        $uuid = $this->postJson($this->base() . '/entrees-cash', ['montant' => 4000, 'donateur' => 'Parrain'])
            ->json('data.uuid');

        $this->postJson($this->base() . "/entrees-cash/{$uuid}/annuler", [])->assertStatus(422); // motif obligatoire
        $this->postJson($this->base() . "/entrees-cash/{$uuid}/annuler", ['motif' => 'Montant saisi par erreur'])
            ->assertOk()->assertJsonPath('data.caisse.cash', 0);
        $this->postJson($this->base() . "/entrees-cash/{$uuid}/annuler", ['motif' => 'Encore'])->assertStatus(409);

        $this->assertDatabaseHas('groupe_entrees_cash', ['uuid' => $uuid, 'motifAnnulation' => 'Montant saisi par erreur']);

        Sanctum::actingAs($this->membre);
        $this->getJson($this->base() . '/mouvements')->assertOk()
            ->assertJsonPath('data.0.type', 'cash')
            ->assertJsonPath('data.0.annule', true); // l'annulation reste visible
    }

    public function test_cash_validation(): void
    {
        Sanctum::actingAs($this->president);

        $this->postJson($this->base() . '/entrees-cash', ['montant' => 0])->assertStatus(422);
        $this->postJson($this->base() . '/entrees-cash', ['montant' => 1000, 'dateEntree' => now()->addDay()->toDateString()])
            ->assertStatus(422);
    }

    // ─── Retraits ────────────────────────────────────────────────────────────

    public function test_withdrawal_requires_a_motive_and_enough_online_balance(): void
    {
        $this->contributionComplete($this->membre, 10000);
        $this->beneficiaire($this->tresorier);
        Sanctum::actingAs($this->tresorier);

        $this->postJson($this->base() . '/retraits', ['montant' => 5000])->assertStatus(422); // motif manquant
        $this->postJson($this->base() . '/retraits', ['montant' => 50000, 'motif' => 'Achat'])->assertStatus(400); // trop élevé
    }

    public function test_cash_is_not_withdrawable_online(): void
    {
        Sanctum::actingAs($this->tresorier);
        $this->postJson($this->base() . '/entrees-cash', ['montant' => 50000]);
        $this->beneficiaire($this->tresorier);

        $this->postJson($this->base() . '/retraits', ['montant' => 5000, 'motif' => 'Achat'])
            ->assertStatus(400); // aucun solde EN LIGNE
    }

    public function test_president_or_treasurer_withdraws_with_motive_visible_to_all_members(): void
    {
        $this->contributionComplete($this->membre, 10000)->forceFill(['created_at' => now()->subHour()])->save();
        $this->beneficiaire($this->president);

        $gateway = Mockery::mock(NotchPayGateway::class)->makePartial();
        $gateway->shouldReceive('initiateTransfer')->once()->andReturn(['id' => 'trf_g1', 'status' => 'complete']);
        $this->app->instance(NotchPayGateway::class, $gateway);

        Sanctum::actingAs($this->president);
        $this->postJson($this->base() . '/retraits', ['montant' => 4000, 'motif' => 'Achat des tenues'])
            ->assertStatus(201)->assertJsonPath('data.motif', 'Achat des tenues');

        $retrait = Retrait::sole();
        $this->assertNull($retrait->churchId);              // jamais rattaché à l'église
        $this->assertSame($this->groupe->id, $retrait->groupeId);

        Sanctum::actingAs($this->membre);
        $this->getJson($this->base())->assertOk()
            ->assertJsonPath('data.caisse.retraits', 4000)
            ->assertJsonPath('data.caisse.soldeEnLigne', 6000);
        $this->getJson($this->base() . '/mouvements')->assertOk()
            ->assertJsonPath('data.0.type', 'retrait')
            ->assertJsonPath('data.0.sens', 'sortie')
            ->assertJsonPath('data.0.libelle', 'Achat des tenues')
            ->assertJsonPath('data.0.montant', 4000);
    }

    public function test_group_withdrawal_never_changes_the_church_balance(): void
    {
        $pasteur = $this->chretien('pasteur');
        $evenement = Evenement::create([
            'uuid' => (string) Str::uuid(), 'churchId' => $this->church->id, 'auteurId' => $pasteur->id,
            'titre' => 'Collecte', 'description' => str_repeat('a', 25),
            'statut' => Evenement::STATUT_ACTIF, 'dateDebut' => now(), 'publishedAt' => now(),
        ]);
        Contribution::create([
            'uuid' => (string) Str::uuid(), 'evenementId' => $evenement->id, 'userId' => $this->membre->id,
            'montant' => 20000, 'frais' => 0, 'montantTotal' => 20000, 'paymentMethod' => 'mobile_money',
            'statut' => Contribution::STATUT_COMPLETED,
        ]);
        $this->contributionComplete($this->membre, 10000);
        $this->beneficiaire($this->president);
        $gateway = Mockery::mock(NotchPayGateway::class)->makePartial();
        $gateway->shouldReceive('initiateTransfer')->once()->andReturn(['id' => 'trf_g2', 'status' => 'complete']);
        $this->app->instance(NotchPayGateway::class, $gateway);

        Sanctum::actingAs($this->president);
        $this->postJson($this->base() . '/retraits', ['montant' => 10000, 'motif' => 'Sortie'])->assertStatus(201);

        Sanctum::actingAs($pasteur);
        $this->getJson('/api/v1/retraits/solde')->assertOk()->assertJsonPath('data.solde', 20000);
        $this->getJson('/api/v1/retraits')->assertOk()->assertJsonCount(0, 'data');
    }

    // ─── Groupe archivé au lieu de supprimé ──────────────────────────────────

    public function test_group_with_money_is_archived_not_deleted_and_history_is_kept(): void
    {
        $contribution = $this->contributionComplete($this->membre, 3000);
        Sanctum::actingAs($this->chretien('pasteur'));

        $this->deleteJson("/api/v1/groupes/{$this->groupe->id}")->assertOk();

        $this->assertNotNull($this->groupe->fresh()->archivedAt);
        $this->assertDatabaseHas('contributions', ['id' => $contribution->id]);
        $this->getJson('/api/v1/groupes')->assertOk()->assertJsonCount(0, 'data');
    }
}
