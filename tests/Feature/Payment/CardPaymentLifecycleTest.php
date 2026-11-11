<?php

namespace Tests\Feature\Payment;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\PaymentTransaction;
use App\Models\Signature;
use App\Models\User;
use App\Modules\Evenement\Application\Jobs\ExpireStaleContributionsJob;
use App\Modules\Evenement\Application\Jobs\SyncEvenementContributionStatusJob;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Payment\Application\Jobs\ExpireStalePaymentTransactionsJob;
use App\Modules\Payment\Application\Jobs\SyncPaymentStatusJob;
use App\Modules\Payment\Application\Services\PaymentGatewayRegistry;
use App\Modules\Signature\Application\Services\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cycle de vie d'un paiement par carte (Flutterwave) : file de vérifications
 * espacées, vérification « à la demande » pendant que l'app suit le paiement,
 * expiration des abandons, et rattrapage d'un paiement expiré puis finalement
 * payé (jamais d'argent encaissé sans contribution ni signature).
 */
class CardPaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Evenement $evenement;

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

        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id, 'actif' => true]);
        $this->evenement = Evenement::create([
            'uuid' => (string) Str::uuid(), 'churchId' => $church->id, 'auteurId' => $pasteur->id,
            'titre' => 'Collecte', 'description' => str_repeat('a', 25),
            'statut' => Evenement::STATUT_ACTIF, 'dateDebut' => now(), 'publishedAt' => now(),
        ]);
        $this->user = User::factory()->create([
            'role' => 'paroissien', 'churchId' => $church->id, 'actif' => true, 'estCommuniant' => true,
        ]);
        Sanctum::actingAs($this->user);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function pendingContribution(int $minutesAgo = 1): Contribution
    {
        $c = Contribution::create([
            'uuid' => $uuid = (string) Str::uuid(), 'evenementId' => $this->evenement->id, 'userId' => $this->user->id,
            'montant' => 1000, 'frais' => 53, 'montantTotal' => 1053, 'paymentMethod' => 'card',
            'paymentGateway' => 'flutterwave', 'payment_reference' => 'SIGNME-' . $uuid,
            'statut' => Contribution::STATUT_PENDING,
        ]);
        $c->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $c;
    }

    private function pendingTransaction(int $minutesAgo = 1): PaymentTransaction
    {
        $month = now()->subMonth()->format('Y-m');
        $t = PaymentTransaction::create([
            'userId' => $this->user->id, 'churchId' => $this->user->churchId, 'paymentMethod' => 'card',
            'paymentGateway' => 'flutterwave', 'amountPerMonth' => 1000, 'monthsCount' => 1, 'totalAmount' => 1000,
            'fees' => 53, 'amountCharged' => 1053, 'months' => json_encode([$month]),
            'payment_reference' => 'SIGNME-SIG-' . Str::uuid(), 'status' => 'pending', 'signatureType' => 'distance',
        ]);
        $t->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $t;
    }

    private function fakeVerification(string $status, mixed $amount = 1053, string $currency = 'XAF'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); // repart d'un simulateur vierge (le premier gagne sinon)
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
            'status' => 'success',
            'data' => ['id' => 1, 'tx_ref' => 'x', 'status' => $status, 'amount' => $amount, 'currency' => $currency],
        ])]);
    }

    private function fakeNotFound(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(
            ['status' => 'error', 'message' => 'No transaction was found for this id'], 404)]);
    }

    private function runContributionExpiry(): void
    {
        (new ExpireStaleContributionsJob())->handle(app(PaymentGatewayRegistry::class), app(ContributionService::class));
    }

    private function runTransactionExpiry(): void
    {
        (new ExpireStalePaymentTransactionsJob())->handle(app(PaymentGatewayRegistry::class), app(SignatureService::class));
    }

    private function syncContribution(Contribution $c): void
    {
        (new SyncEvenementContributionStatusJob($c->id, $c->payment_reference))
            ->handle(app(PaymentGatewayRegistry::class), app(ContributionService::class));
    }

    // ─── File de vérifications espacées ──────────────────────────────────────

    public function test_a_card_payment_gets_spaced_checks_for_about_fourteen_minutes(): void
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response([
            'status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/x'],
        ])]);

        $this->postJson("/api/v1/evenements/{$this->evenement->uuid}/contributions", [
            'montant' => 1000, 'paymentMethod' => 'card',
        ])->assertStatus(201);

        $delays = config('payment.polling.card_delays_seconds');
        Queue::assertPushed(SyncEvenementContributionStatusJob::class, count($delays));
        $this->assertSame(840, max($delays));
        $this->assertGreaterThan(config('payment.polling.delays_seconds')[8], max($delays)); // bien au-delà de Mobile Money
    }

    // ─── Vérification à la demande pendant que l'app suit le paiement ────────

    public function test_polling_the_status_confirms_a_card_payment_without_waiting_for_the_webhook(): void
    {
        $c = $this->pendingContribution();
        $this->fakeVerification('successful');

        $this->getJson("/api/v1/contributions/transaction/{$c->uuid}/status")
            ->assertOk()->assertJsonPath('data.statut', 'completed');

        $this->assertSame(Contribution::STATUT_COMPLETED, $c->fresh()->statut);
        $this->assertSame(1000, $this->evenement->fresh()->montantCollecte);
    }

    public function test_live_checks_are_throttled(): void
    {
        $c = $this->pendingContribution();
        $this->fakeVerification('pending');

        $this->getJson("/api/v1/contributions/transaction/{$c->uuid}/status")->assertOk();
        $this->getJson("/api/v1/contributions/transaction/{$c->uuid}/status")->assertOk();
        $this->getJson("/api/v1/contributions/transaction/{$c->uuid}/status")->assertOk();

        Http::assertSentCount(1); // une seule vérification dans la fenêtre
    }

    public function test_a_provider_outage_never_breaks_the_status_endpoint(): void
    {
        $c = $this->pendingContribution();
        Http::fake(['api.flutterwave.com/*' => Http::response('boom', 500)]);

        $this->getJson("/api/v1/contributions/transaction/{$c->uuid}/status")
            ->assertOk()->assertJsonPath('data.statut', 'pending');
    }

    public function test_polling_a_signature_payment_creates_the_signatures_immediately(): void
    {
        $t = $this->pendingTransaction();
        $this->fakeVerification('successful');

        $this->getJson("/api/v1/signatures/transaction/{$t->id}/status")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame(1, Signature::where('userId', $this->user->id)->count());
    }

    // ─── Expiration des abandons ─────────────────────────────────────────────

    public function test_an_abandoned_card_payment_expires_and_is_flagged_as_expired(): void
    {
        $c = $this->pendingContribution(minutesAgo: 40);
        $t = $this->pendingTransaction(minutesAgo: 40);
        $this->fakeNotFound();

        $this->runContributionExpiry();
        $this->runTransactionExpiry();

        $this->assertSame(Contribution::STATUT_FAILED, $c->fresh()->statut);
        $this->assertTrue($c->fresh()->estExpiree());
        $this->assertSame('failed', $t->fresh()->status);
        $this->assertTrue($t->fresh()->estExpiree());
        $this->assertSame(0, $this->evenement->fresh()->montantCollecte);
    }

    public function test_a_payment_that_really_failed_is_never_recovered(): void
    {
        $c = $this->pendingContribution();
        $this->fakeVerification('failed');
        $this->syncContribution($c);
        $this->assertSame(Contribution::STATUT_FAILED, $c->fresh()->statut);
        $this->assertFalse($c->fresh()->estExpiree());

        $this->fakeVerification('successful'); // une réponse tardive ne ressuscite pas un échec réel
        $this->syncContribution($c);

        $this->assertSame(Contribution::STATUT_FAILED, $c->fresh()->statut);
    }

    // ─── Rattrapage : expiré puis finalement payé ────────────────────────────

    public function test_an_expired_card_payment_that_was_paid_afterwards_is_credited_once(): void
    {
        $c = $this->pendingContribution(minutesAgo: 40);
        $this->fakeNotFound();
        $this->runContributionExpiry();
        $this->assertTrue($c->fresh()->estExpiree());

        $this->fakeVerification('successful'); // le fidèle a payé sur le lien resté ouvert
        $this->syncContribution($c);
        $this->syncContribution($c);           // webhook en double : sans effet

        $c = $c->fresh();
        $this->assertSame(Contribution::STATUT_COMPLETED, $c->statut);
        $this->assertTrue($c->payment_metadata['late_confirmation']);
        $this->assertSame(1000, $this->evenement->fresh()->montantCollecte);
        $this->assertSame(1, $this->evenement->fresh()->nombreContributions);
    }

    public function test_an_expired_payment_with_a_wrong_amount_stays_expired(): void
    {
        $c = $this->pendingContribution(minutesAgo: 40);
        $this->fakeNotFound();
        $this->runContributionExpiry();

        $this->fakeVerification('successful', amount: 500);
        $this->syncContribution($c);

        $this->assertSame(Contribution::STATUT_FAILED, $c->fresh()->statut);
        $this->assertSame(0, $this->evenement->fresh()->montantCollecte);
    }

    public function test_the_scheduled_job_recovers_a_late_payment_even_if_the_webhook_was_lost(): void
    {
        $c = $this->pendingContribution(minutesAgo: 40);
        $this->fakeNotFound();
        $this->runContributionExpiry();

        $this->fakeVerification('successful');
        $this->runContributionExpiry(); // passage suivant du planificateur

        $this->assertSame(Contribution::STATUT_COMPLETED, $c->fresh()->statut);
        $this->assertSame(1000, $this->evenement->fresh()->montantCollecte);
    }

    public function test_an_expired_signature_payment_paid_afterwards_creates_the_signatures(): void
    {
        $t = $this->pendingTransaction(minutesAgo: 40);
        $this->fakeNotFound();
        $this->runTransactionExpiry();
        $this->assertTrue($t->fresh()->estExpiree());
        $this->assertSame(0, Signature::count());

        $this->fakeVerification('successful');
        (new SyncPaymentStatusJob($t->id, $t->payment_reference))
            ->handle(app(PaymentGatewayRegistry::class), app(SignatureService::class));

        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame(1, Signature::where('userId', $this->user->id)->where('status', 'completed')->count());
    }

    public function test_the_scheduled_job_recovers_a_late_signature_payment(): void
    {
        $t = $this->pendingTransaction(minutesAgo: 40);
        $this->fakeNotFound();
        $this->runTransactionExpiry();

        $this->fakeVerification('successful');
        $this->runTransactionExpiry();

        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame(1, Signature::where('userId', $this->user->id)->count());
    }
}
