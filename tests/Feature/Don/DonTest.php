<?php

namespace Tests\Feature\Don;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\MFACredential;
use App\Models\User;
use App\Modules\Evenement\Application\Jobs\TerminateExpiredEvenementsJob;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Tests Feature - Service « Faire un don » (don libre hors événement).
 *
 * Le paiement NotchPay est simulé : aucun appel réseau, aucun argent réel.
 */
class DonTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/dons';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake(); // pas de Redis réel pour le polling de secours
    }

    private function gateway(int $times = 1): void
    {
        $gateway = Mockery::mock(NotchPayGateway::class);
        $gateway->shouldReceive('initiateMobileMoney')->times($times)->andReturnUsing(fn () => 'DON-REF-' . uniqid());
        $this->app->instance(NotchPayGateway::class, $gateway);
    }

    private function churchWithPastor(): array
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id, 'actif' => true]);
        $church->update(['pastorId' => $pasteur->id, 'pastorNom' => $pasteur->nom]);

        return [$church, $pasteur];
    }

    private function member(Church $church, string $role = 'paroissien'): User
    {
        return User::factory()->create(['role' => $role, 'churchId' => $church->id, 'actif' => true]);
    }

    private function payload(int $montant = 1000): array
    {
        return [
            'montant'         => $montant,
            'note'            => 'Merci Seigneur',
            'paymentMethod'   => 'mobile_money',
            'paymentOperator' => 'ORANGE MONEY',
            'paymentNumber'   => '699112233',
        ];
    }

    // ─── Accès ───────────────────────────────────────────────────────────────

    public function test_guest_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload())->assertStatus(401);
    }

    public function test_admin_cannot_use_the_client_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'administrateur', 'actif' => true, 'is_super_admin' => true]);
        MFACredential::create(['user_id' => $admin->id, 'mfa_enabled' => true, 'mfa_type' => 'totp']);
        Sanctum::actingAs($admin);

        $this->postJson(self::URL, $this->payload())->assertStatus(403);
    }

    public function test_user_without_church_cannot_donate(): void
    {
        $this->gateway(0);
        Sanctum::actingAs(User::factory()->create(['role' => 'paroissien', 'churchId' => null, 'actif' => true]));

        $this->postJson(self::URL, $this->payload())->assertStatus(403);
    }

    /** @dataProvider clientRoles */
    public function test_every_client_role_can_donate(string $role): void
    {
        $this->gateway();
        [$church] = $this->churchWithPastor();
        Sanctum::actingAs($this->member($church, $role));

        $this->postJson(self::URL, $this->payload())->assertStatus(201)->assertJsonPath('success', true);
    }

    public static function clientRoles(): array
    {
        return [['paroissien'], ['ancien'], ['secretaire'], ['tresorier'], ['pasteur']];
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_validation_is_the_same_as_a_contribution(): void
    {
        $this->gateway(0);
        [$church] = $this->churchWithPastor();
        Sanctum::actingAs($this->member($church));

        $this->postJson(self::URL, [])->assertStatus(422);
        $this->postJson(self::URL, $this->payload(505))->assertStatus(422); // pas multiple de 50
        $this->postJson(self::URL, [...$this->payload(), 'paymentNumber' => '12'])->assertStatus(422);
        $this->postJson(self::URL, [...$this->payload(), 'paymentOperator' => 'AUTRE'])->assertStatus(422);
    }

    // ─── Événement « Dons libres » ───────────────────────────────────────────

    public function test_first_donation_creates_the_hidden_event_authored_by_the_pastor(): void
    {
        $this->gateway();
        [$church, $pasteur] = $this->churchWithPastor();
        Sanctum::actingAs($this->member($church));

        $this->postJson(self::URL, $this->payload(1500))->assertStatus(201);

        $don = Evenement::where('churchId', $church->id)->where('type', Evenement::TYPE_DON_LIBRE)->sole();
        $this->assertSame('Dons libres', $don->titre);
        $this->assertSame(Evenement::STATUT_ACTIF, $don->statut);
        $this->assertSame($pasteur->id, $don->auteurId);

        $contribution = Contribution::sole();
        $this->assertSame($don->id, $contribution->evenementId);
        $this->assertSame(1500, $contribution->montant);
        $this->assertSame(Contribution::STATUT_PENDING, $contribution->statut);
    }

    public function test_the_event_is_reused_and_each_church_has_its_own(): void
    {
        $this->gateway(3);
        [$churchA] = $this->churchWithPastor();
        [$churchB] = $this->churchWithPastor();
        $donorA = $this->member($churchA);
        $donorB = $this->member($churchB);

        Sanctum::actingAs($donorA);
        $this->postJson(self::URL, $this->payload())->assertStatus(201);
        Contribution::where('userId', $donorA->id)->update(['statut' => Contribution::STATUT_FAILED]);
        $this->postJson(self::URL, $this->payload())->assertStatus(201);

        Sanctum::actingAs($donorB);
        $this->postJson(self::URL, $this->payload())->assertStatus(201);

        $this->assertSame(2, Evenement::where('type', Evenement::TYPE_DON_LIBRE)->count());
        $this->assertSame(2, Evenement::where('churchId', $churchA->id)->count() + Evenement::where('churchId', $churchB->id)->count());
    }

    public function test_author_falls_back_to_a_member_when_the_church_has_no_pastor(): void
    {
        $this->gateway();
        $church = Church::factory()->create();
        $donor = $this->member($church);
        Sanctum::actingAs($donor);

        $this->postJson(self::URL, $this->payload())->assertStatus(201);

        $this->assertSame($donor->id, Evenement::where('type', Evenement::TYPE_DON_LIBRE)->value('auteurId'));
    }

    public function test_a_second_donation_while_one_is_pending_is_refused(): void
    {
        $this->gateway();
        [$church] = $this->churchWithPastor();
        Sanctum::actingAs($this->member($church));

        $this->postJson(self::URL, $this->payload())->assertStatus(201);
        $this->postJson(self::URL, $this->payload())->assertStatus(409);
    }

    // ─── L'événement « Dons libres » reste invisible et intouchable ──────────

    public function test_donation_event_is_hidden_from_feed_gestion_and_badges(): void
    {
        $this->gateway();
        [$church, $pasteur] = $this->churchWithPastor();
        $donor = $this->member($church);
        Sanctum::actingAs($donor);
        $this->postJson(self::URL, $this->payload())->assertStatus(201);

        $this->getJson('/api/v1/evenements')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($pasteur);
        $this->getJson('/api/v1/evenements/gestion')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($donor);
        $this->getJson('/api/v1/notifications/badges')->assertOk()->assertJsonPath('data.evenements', 0);
    }

    public function test_real_events_still_appear_next_to_the_hidden_one(): void
    {
        $this->gateway();
        [$church, $pasteur] = $this->churchWithPastor();
        Evenement::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'churchId' => $church->id, 'auteurId' => $pasteur->id,
            'titre' => 'Excellence scolaire', 'description' => str_repeat('a', 25),
            'statut' => Evenement::STATUT_ACTIF, 'dateDebut' => now(), 'publishedAt' => now(),
        ]);
        Sanctum::actingAs($this->member($church));
        $this->postJson(self::URL, $this->payload())->assertStatus(201);

        $this->getJson('/api/v1/evenements')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Excellence scolaire');
    }

    public function test_donation_event_cannot_be_modified_or_closed_even_by_its_author(): void
    {
        $this->gateway();
        [$church, $pasteur] = $this->churchWithPastor();
        Sanctum::actingAs($this->member($church));
        $this->postJson(self::URL, $this->payload())->assertStatus(201);
        $uuid = Evenement::where('type', Evenement::TYPE_DON_LIBRE)->value('uuid');

        Sanctum::actingAs($pasteur);
        $this->putJson("/api/v1/evenements/{$uuid}", ['titre' => 'Piraté'])->assertStatus(403);
        $this->postJson("/api/v1/evenements/{$uuid}/terminer")->assertStatus(403);
        $this->postJson("/api/v1/evenements/{$uuid}/archiver")->assertStatus(403);

        $this->assertSame('Dons libres', Evenement::where('uuid', $uuid)->value('titre'));
    }

    public function test_the_automatic_termination_job_never_closes_the_donation_event(): void
    {
        $this->gateway();
        [$church] = $this->churchWithPastor();
        Sanctum::actingAs($this->member($church));
        $this->postJson(self::URL, $this->payload())->assertStatus(201);

        (new TerminateExpiredEvenementsJob())->handle();

        $this->assertSame(
            Evenement::STATUT_ACTIF,
            Evenement::where('type', Evenement::TYPE_DON_LIBRE)->value('statut')
        );
    }

    // ─── Suivi du paiement ───────────────────────────────────────────────────

    public function test_the_donation_status_can_be_polled_like_a_contribution(): void
    {
        $this->gateway();
        [$church] = $this->churchWithPastor();
        Sanctum::actingAs($this->member($church));
        $uuid = $this->postJson(self::URL, $this->payload())->assertStatus(201)->json('data.uuid');

        $this->getJson("/api/v1/contributions/transaction/{$uuid}/status")
            ->assertOk()
            ->assertJsonPath('data.statut', 'pending');
    }
}
