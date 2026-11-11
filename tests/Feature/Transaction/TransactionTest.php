<?php

namespace Tests\Feature\Transaction;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\MFACredential;
use App\Models\PaymentTransaction;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests Feature - « Mes transactions » : toutes les opérations financières
 * de l'utilisateur connecté (contributions, dons, signatures, espèces).
 */
class TransactionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/transactions';

    private Church $church;
    private User $pasteur;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->church = Church::factory()->create(['nom' => 'EEC Messamendongo']);
        $this->pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $this->church->id, 'actif' => true]);
        $this->me = User::factory()->create(['role' => 'paroissien', 'churchId' => $this->church->id, 'actif' => true]);
        Sanctum::actingAs($this->me);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function evenement(string $titre = 'Excellence scolaire', string $type = Evenement::TYPE_COLLECTE): Evenement
    {
        return Evenement::create([
            'uuid' => (string) Str::uuid(), 'churchId' => $this->church->id, 'auteurId' => $this->pasteur->id,
            'titre' => $titre, 'description' => str_repeat('a', 25), 'statut' => Evenement::STATUT_ACTIF,
            'type' => $type, 'dateDebut' => now(), 'publishedAt' => now(),
        ]);
    }

    private function contribution(Evenement $e, array $overrides = [], ?User $user = null): Contribution
    {
        return Contribution::create(array_merge([
            'uuid' => (string) Str::uuid(), 'evenementId' => $e->id, 'userId' => ($user ?? $this->me)->id,
            'montant' => 1000, 'frais' => 100, 'montantTotal' => 1100,
            'paymentMethod' => 'mobile_money', 'paymentGateway' => 'notchpay',
            'paymentOperator' => 'ORANGE MONEY', 'paymentNumber' => '699803165',
            'payment_reference' => 'REF-' . Str::random(8), 'statut' => 'completed',
        ], $overrides));
    }

    private function paiementSignature(array $overrides = [], ?User $user = null): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'userId' => ($user ?? $this->me)->id, 'churchId' => $this->church->id,
            'paymentOperator' => 'MTN MOBILE MONEY', 'paymentNumber' => '677112233',
            'paymentMethod' => 'mobile_money', 'amountPerMonth' => 500, 'monthsCount' => 2,
            'totalAmount' => 1000, 'fees' => 100, 'amountCharged' => 1100,
            'months' => ['2026-01', '2026-02'], 'status' => 'completed',
            'signatureType' => 'distance', 'payment_reference' => 'SIG-' . Str::random(8),
        ], $overrides));
    }

    private function signatureEspeces(string $month, string $at = '2026-03-05 10:00:00', ?User $user = null): Signature
    {
        [$year, $num] = explode('-', $month);

        $s = Signature::create([
            'userId' => ($user ?? $this->me)->id, 'nomParoissien' => 'X', 'churchId' => $this->church->id,
            'monthIndex' => (int) $num - 1, 'month' => $month, 'year' => (int) $year,
            'amount' => 500, 'fees' => 0, 'paymentMethod' => 'ESPECES', 'signatureType' => 'presentiel',
            'status' => 'completed', 'ancienId' => $this->pasteur->id, 'ancienNom' => 'Ancien Paul',
        ]);
        $s->forceFill(['created_at' => $at])->save();

        return $s;
    }

    // ─── Accès ───────────────────────────────────────────────────────────────

    public function test_guest_is_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_admin_cannot_use_the_client_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'administrateur', 'actif' => true, 'is_super_admin' => true]);
        MFACredential::create(['user_id' => $admin->id, 'mfa_enabled' => true, 'mfa_type' => 'totp']);
        Sanctum::actingAs($admin);

        $this->getJson(self::URL)->assertStatus(403);
    }

    /** @dataProvider clientRoles */
    public function test_every_client_role_gets_its_own_list(string $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role, 'churchId' => $this->church->id, 'actif' => true]));

        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
    }

    public static function clientRoles(): array
    {
        return [['paroissien'], ['ancien'], ['secretaire'], ['tresorier'], ['pasteur']];
    }

    // ─── Isolation ───────────────────────────────────────────────────────────

    public function test_only_my_own_transactions_are_listed(): void
    {
        $other = User::factory()->create(['role' => 'paroissien', 'churchId' => $this->church->id, 'actif' => true]);
        $e = $this->evenement();
        $this->contribution($e);
        $this->contribution($e, [], $other);
        $this->paiementSignature([], $other);
        $this->signatureEspeces('2026-03', user: $other);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1);
    }

    // ─── Contenu ─────────────────────────────────────────────────────────────

    public function test_an_event_contribution_by_mobile_money(): void
    {
        $this->contribution($this->evenement('Excellence scolaire'), ['statut' => 'completed']);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.type', 'contribution')
            ->assertJsonPath('data.0.motif', 'Contribution — Excellence scolaire')
            ->assertJsonPath('data.0.eglise', 'EEC Messamendongo')
            ->assertJsonPath('data.0.montant', 1000)
            ->assertJsonPath('data.0.frais', 100)
            ->assertJsonPath('data.0.montantTotal', 1100)
            ->assertJsonPath('data.0.statut', 'completed')
            ->assertJsonPath('data.0.paymentMethod', 'mobile_money')
            ->assertJsonPath('data.0.paymentLabel', 'Orange Money')
            ->assertJsonPath('data.0.paymentNumber', '699803165');
    }

    public function test_a_free_donation_is_typed_don(): void
    {
        $this->contribution($this->evenement('Dons libres', Evenement::TYPE_DON_LIBRE));

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.type', 'don')
            ->assertJsonPath('data.0.motif', 'Don à l\'église');
    }

    public function test_a_card_payment_exposes_only_brand_and_last_four_digits(): void
    {
        $this->contribution($this->evenement(), [
            'paymentMethod' => 'card', 'paymentGateway' => 'flutterwave',
            'paymentOperator' => null, 'paymentNumber' => null,
            'payment_metadata' => ['card' => ['last4' => '4242', 'brand' => 'VISA'], 'secret' => 'x'],
        ]);

        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.paymentMethod', 'card')
            ->assertJsonPath('data.0.paymentLabel', 'Carte bancaire')
            ->assertJsonPath('data.0.cardBrand', 'VISA')
            ->assertJsonPath('data.0.cardLast4', '4242')
            ->assertJsonPath('data.0.paymentNumber', null);

        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_a_signature_payment_lists_its_months(): void
    {
        $this->paiementSignature(['months' => ['2026-01', '2026-02']]);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.type', 'signature')
            ->assertJsonPath('data.0.motif', 'Sainte-Cène — 2 mois')
            ->assertJsonPath('data.0.details', ['Janvier 2026', 'Février 2026'])
            ->assertJsonPath('data.0.montant', 1000)
            ->assertJsonPath('data.0.montantTotal', 1100)
            ->assertJsonPath('data.0.paymentLabel', 'MTN Mobile Money');
    }

    public function test_months_double_encoded_as_json_are_still_understood(): void
    {
        $t = $this->paiementSignature();
        // Le service d'initiation enregistre json_encode() dans une colonne JSON.
        \DB::table('payment_transactions')->where('id', $t->id)->update(['months' => json_encode(json_encode(['2026-05']))]);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.motif', 'Sainte-Cène — Mai 2026');
    }

    public function test_cash_signatures_of_one_visit_are_a_single_transaction(): void
    {
        $this->signatureEspeces('2026-03', '2026-03-05 10:00:10');
        $this->signatureEspeces('2026-04', '2026-03-05 10:00:40');   // même passage (même minute)
        $this->signatureEspeces('2026-05', '2026-04-02 09:30:00');   // autre passage

        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data');

        $response->assertJsonPath('data.0.paymentLabel', 'Espèces')
            ->assertJsonPath('data.0.paymentMethod', 'cash')
            ->assertJsonPath('data.0.motif', 'Sainte-Cène — Mai 2026')
            ->assertJsonPath('data.0.receivedBy', 'Ancien Paul')
            ->assertJsonPath('data.0.paymentNumber', null)
            ->assertJsonPath('data.1.motif', 'Sainte-Cène — 2 mois')
            ->assertJsonPath('data.1.montant', 1000)
            ->assertJsonPath('data.1.montantTotal', 1000)
            ->assertJsonPath('data.1.details', ['Mars 2026', 'Avril 2026']);
    }

    public function test_statuses_are_reported_as_they_are(): void
    {
        $e = $this->evenement();
        $this->contribution($e, ['statut' => 'completed']);
        $this->contribution($e, ['statut' => 'failed']);
        $this->contribution($e, ['statut' => 'pending']);

        $statuts = collect($this->getJson(self::URL)->assertOk()->json('data'))->pluck('statut')->sort()->values()->all();

        $this->assertSame(['completed', 'failed', 'pending'], $statuts);
    }

    // ─── Tri, filtres, pagination ────────────────────────────────────────────

    public function test_all_sources_are_merged_from_most_recent_to_oldest(): void
    {
        $c = $this->contribution($this->evenement());
        $c->forceFill(['created_at' => '2026-02-01 08:00:00'])->save();
        $p = $this->paiementSignature();
        $p->forceFill(['created_at' => '2026-04-01 08:00:00'])->save();
        $this->signatureEspeces('2026-03', '2026-03-01 08:00:00');

        $types = collect($this->getJson(self::URL)->assertOk()->json('data'))->pluck('paymentMethod')->all();

        $this->assertSame(['mobile_money', 'cash', 'mobile_money'], $types);
        $this->assertSame(
            ['signature', 'signature', 'contribution'],
            collect($this->getJson(self::URL)->json('data'))->pluck('type')->all()
        );
    }

    public function test_filters_by_status_and_type(): void
    {
        $e = $this->evenement();
        $this->contribution($e, ['statut' => 'completed']);
        $this->contribution($e, ['statut' => 'failed']);
        $this->contribution($this->evenement('Dons libres', Evenement::TYPE_DON_LIBRE));
        $this->paiementSignature();

        $this->getJson(self::URL . '?statut=failed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL . '?type=don')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL . '?type=signature')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL . '?type=contribution&statut=completed')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_ignores_case_and_accents(): void
    {
        $this->contribution($this->evenement('Excellence scolaire'));
        $this->paiementSignature(['months' => ['2026-02']]);

        $this->getJson(self::URL . '?q=SCOLAIRE')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL . '?q=fevrier')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL . '?q=orange')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL . '?q=677112233')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL . '?q=introuvable')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pagination_covers_every_transaction_once(): void
    {
        $e = $this->evenement();
        foreach (range(1, 7) as $i) {
            $this->contribution($e)->forceFill(['created_at' => now()->subMinutes($i)])->save();
        }

        $first = $this->getJson(self::URL . '?per_page=3&page=1')->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('pagination.total', 7)->assertJsonPath('pagination.last_page', 3);
        $last = $this->getJson(self::URL . '?per_page=3&page=3')->assertOk()->assertJsonCount(1, 'data');

        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($this->getJson(self::URL . '?per_page=3&page=2')->json('data'), 'id'),
            array_column($last->json('data'), 'id'),
        );
        $this->assertCount(7, array_unique($ids));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson(self::URL . '?statut=bizarre')->assertStatus(422);
        $this->getJson(self::URL . '?type=bizarre')->assertStatus(422);
        $this->getJson(self::URL . '?per_page=500')->assertStatus(422);
    }
}
