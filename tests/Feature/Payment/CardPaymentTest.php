<?php

namespace Tests\Feature\Payment;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\User;
use App\Modules\Evenement\Application\Jobs\ExpireStaleContributionsJob;
use App\Modules\Evenement\Application\Jobs\SyncEvenementContributionStatusJob;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Payment\Application\Services\PaymentGatewayRegistry;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Tests Feature - Paiement par carte bancaire (Flutterwave).
 *
 * Aucun appel réseau réel : Flutterwave est simulé via Http::fake().
 */
class CardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const CHECKOUT = 'https://checkout.flutterwave.test/pay/abc123';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake(); // pas de Redis réel pour le polling de secours
        config([
            'payment.card.enabled'            => true,
            'payment.card.currency'           => 'XAF',
            'payment.card.fee_percent'        => 5.0,
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-xyz',
            'services.flutterwave.secret_hash' => 'secret-hash-test',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function setupEvent(): array
    {
        $church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id, 'actif' => true]);
        $evenement = Evenement::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'churchId' => $church->id, 'auteurId' => $pasteur->id,
            'titre' => 'Excellence scolaire', 'description' => str_repeat('a', 25),
            'statut' => Evenement::STATUT_ACTIF, 'dateDebut' => now(), 'publishedAt' => now(),
        ]);
        $donor = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id, 'actif' => true]);
        Sanctum::actingAs($donor);

        return [$evenement, $donor];
    }

    private function fakeCheckoutCreated(): void
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response([
            'status' => 'success', 'message' => 'Hosted Link', 'data' => ['link' => self::CHECKOUT],
        ])]);
    }

    private function cardPayload(int $montant = 1000): array
    {
        return ['montant' => $montant, 'note' => 'Merci', 'paymentMethod' => 'card'];
    }

    private function noNotchCall(): void
    {
        $notch = Mockery::mock(NotchPayGateway::class);
        $notch->shouldNotReceive('initiateMobileMoney');
        $this->app->instance(NotchPayGateway::class, $notch);
    }

    /** Une contribution carte en attente, prête à être vérifiée. */
    private function pendingCardContribution(int $montant = 1000): Contribution
    {
        [$evenement, $donor] = $this->setupEvent();

        return Contribution::create([
            'uuid' => $uuid = (string) \Illuminate\Support\Str::uuid(),
            'evenementId' => $evenement->id, 'userId' => $donor->id,
            'montant' => $montant, 'frais' => 53, 'montantTotal' => $montant + 53,
            'paymentMethod' => 'card', 'paymentGateway' => 'flutterwave',
            'payment_reference' => 'SIGNME-' . $uuid, 'statut' => Contribution::STATUT_PENDING,
        ]);
    }

    private function fakeVerification(string $status, mixed $amount = 1053, string $currency = 'XAF'): void
    {
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
            'status' => 'success',
            'data' => ['id' => 998877, 'tx_ref' => 'x', 'status' => $status, 'amount' => $amount, 'currency' => $currency],
        ])]);
    }

    private function runSync(Contribution $c): void
    {
        (new SyncEvenementContributionStatusJob($c->id, $c->payment_reference))
            ->handle(app(PaymentGatewayRegistry::class), app(ContributionService::class));
    }

    // ─── Initiation ──────────────────────────────────────────────────────────

    public function test_card_payment_creates_the_hosted_checkout_and_never_touches_notchpay(): void
    {
        $this->fakeCheckoutCreated();
        $this->noNotchCall();
        [$evenement, $donor] = $this->setupEvent();

        $response = $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload(1000))
            ->assertStatus(201)
            ->assertJsonPath('data.paymentMethod', 'card')
            ->assertJsonPath('data.checkoutUrl', self::CHECKOUT)
            ->assertJsonPath('data.statut', 'pending')
            ->assertJsonPath('data.montant', 1000)
            ->assertJsonPath('data.frais', 53)            // 1000 / 0.95 = 1052,6 → 1053
            ->assertJsonPath('data.montantTotal', 1053);

        $contribution = Contribution::where('uuid', $response->json('data.uuid'))->sole();
        $this->assertSame('flutterwave', $contribution->paymentGateway);
        $this->assertNull($contribution->paymentOperator);
        $this->assertNull($contribution->paymentNumber);
        $this->assertSame('SIGNME-' . $contribution->uuid, $contribution->payment_reference);

        Http::assertSent(function (Request $request) use ($contribution, $donor) {
            $b = $request->data();

            return $request->url() === 'https://api.flutterwave.com/v3/payments'
                && $request->hasHeader('Authorization', 'Bearer FLWSECK_TEST-xyz')
                && $b['tx_ref'] === 'SIGNME-' . $contribution->uuid
                && $b['amount'] === 1053
                && $b['currency'] === 'XAF'
                && $b['payment_options'] === 'card'
                && $b['redirect_url'] === route('payment.callback')
                && $b['customer']['email'] === $donor->email;
        });
        Queue::assertPushed(SyncEvenementContributionStatusJob::class);
    }

    public function test_the_client_cannot_impose_fees_or_a_total(): void
    {
        $this->fakeCheckoutCreated();
        [$evenement] = $this->setupEvent();

        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", [
            ...$this->cardPayload(1000), 'frais' => 0, 'montantTotal' => 1000,
        ])->assertStatus(201)->assertJsonPath('data.montantTotal', 1053);
    }

    public function test_the_checkout_url_is_only_exposed_to_the_donor_while_pending(): void
    {
        $this->fakeCheckoutCreated();
        [$evenement] = $this->setupEvent();
        $uuid = $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload())
            ->assertStatus(201)->json('data.uuid');

        $this->getJson("/api/v1/contributions/transaction/{$uuid}/status")->assertOk()
            ->assertJsonPath('data.checkoutUrl', self::CHECKOUT);

        Contribution::where('uuid', $uuid)->update(['statut' => 'completed']);
        $this->getJson("/api/v1/contributions/transaction/{$uuid}/status")->assertOk()
            ->assertJsonMissingPath('data.checkoutUrl');
    }

    public function test_card_payments_can_be_switched_off(): void
    {
        config(['payment.card.enabled' => false]);
        Http::fake();
        [$evenement] = $this->setupEvent();

        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload())->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, Contribution::count());
    }

    public function test_a_flutterwave_failure_creates_nothing_and_does_not_block_the_donor(): void
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::sequence()
            ->push(['status' => 'error', 'message' => 'boom'], 500)
            ->push(['status' => 'success', 'data' => ['link' => self::CHECKOUT]])]);
        [$evenement] = $this->setupEvent();

        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload())->assertStatus(503);
        // Comme pour le Mobile Money, l'échec d'initiation annule la création.
        $this->assertSame(0, Contribution::count());

        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload())->assertStatus(201);
    }

    public function test_missing_api_key_is_a_503_without_calling_flutterwave(): void
    {
        config(['services.flutterwave.secret_key' => null]);
        Http::fake();
        [$evenement] = $this->setupEvent();

        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_mobile_money_still_requires_operator_and_number(): void
    {
        Http::fake();
        [$evenement] = $this->setupEvent();

        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", [
            'montant' => 1000, 'paymentMethod' => 'mobile_money',
        ])->assertStatus(422);
        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", [
            'montant' => 1000, 'paymentMethod' => 'paypal',
        ])->assertStatus(422);
    }

    public function test_card_amounts_follow_the_same_bounds_as_other_contributions(): void
    {
        Http::fake();
        [$evenement] = $this->setupEvent();

        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload(505))->assertStatus(422);
        $this->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", $this->cardPayload(100))->assertStatus(422);
    }

    // ─── Paramètres exposés à l'app ──────────────────────────────────────────

    public function test_card_config_is_exposed_to_authenticated_users(): void
    {
        $this->setupEvent();

        $this->getJson('/api/v1/payments/card-config')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.currency', 'XAF')
            ->assertJsonPath('data.feePercent', 5);
    }

    public function test_card_config_requires_authentication(): void
    {
        $this->getJson('/api/v1/payments/card-config')->assertStatus(401);
    }

    // ─── Webhook : simple déclencheur ────────────────────────────────────────

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $c = $this->pendingCardContribution();

        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $c->payment_reference]],
            ['verif-hash' => 'mauvais'])->assertStatus(403);
        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $c->payment_reference]])->assertStatus(403);

        Queue::assertNotPushed(SyncEvenementContributionStatusJob::class);
    }

    public function test_webhook_without_configured_secret_is_always_rejected(): void
    {
        config(['services.flutterwave.secret_hash' => null]);
        $c = $this->pendingCardContribution();

        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $c->payment_reference]],
            ['verif-hash' => ''])->assertStatus(403);
    }

    public function test_valid_webhook_only_triggers_a_verification_and_never_credits_by_itself(): void
    {
        $c = $this->pendingCardContribution();

        $this->postJson('/api/v1/webhooks/flutterwave',
            ['event' => 'charge.completed', 'data' => ['tx_ref' => $c->payment_reference, 'status' => 'successful', 'amount' => 1053]],
            ['verif-hash' => 'secret-hash-test'])->assertOk();

        Queue::assertPushed(SyncEvenementContributionStatusJob::class,
            fn ($job) => $job->contributionId === $c->id && $job->paymentReference === $c->payment_reference);
        $this->assertSame('pending', $c->fresh()->statut);
        $this->assertSame(0, Evenement::first()->montantCollecte);
    }

    public function test_webhook_signed_with_hmac_is_also_accepted(): void
    {
        $c = $this->pendingCardContribution();
        $body = json_encode(['data' => ['tx_ref' => $c->payment_reference]]);
        $signature = base64_encode(hash_hmac('sha256', $body, 'secret-hash-test', true));

        $this->call('POST', '/api/v1/webhooks/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_FLUTTERWAVE_SIGNATURE' => $signature,
        ], $body)->assertOk();

        Queue::assertPushed(SyncEvenementContributionStatusJob::class);
    }

    public function test_authentic_webhook_for_an_unknown_reference_is_acknowledged_and_ignored(): void
    {
        $this->setupEvent();

        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => 'INCONNU-1']],
            ['verif-hash' => 'secret-hash-test'])->assertOk();

        Queue::assertNotPushed(SyncEvenementContributionStatusJob::class);
    }

    public function test_webhook_never_matches_a_mobile_money_contribution(): void
    {
        $c = $this->pendingCardContribution();
        $c->update(['paymentGateway' => 'notchpay', 'paymentMethod' => 'mobile_money']);

        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $c->payment_reference]],
            ['verif-hash' => 'secret-hash-test'])->assertOk();

        Queue::assertNotPushed(SyncEvenementContributionStatusJob::class);
    }

    // ─── Vérification auprès de Flutterwave : seule source de vérité ─────────

    public function test_a_verified_successful_payment_credits_the_event_with_the_amount_not_the_fees(): void
    {
        $c = $this->pendingCardContribution(1000);
        $this->fakeVerification('successful', 1053);

        $this->runSync($c);

        $this->assertSame('completed', $c->fresh()->statut);
        $event = Evenement::first();
        $this->assertSame(1000, $event->montantCollecte);        // l'église reçoit le montant, pas les frais
        $this->assertSame(1, $event->nombreContributions);
    }

    public function test_only_the_card_brand_and_last_four_digits_are_kept(): void
    {
        $c = $this->pendingCardContribution(1000);
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
            'status' => 'success',
            'data' => [
                'id' => 1, 'tx_ref' => 'x', 'status' => 'successful', 'amount' => 1053, 'currency' => 'XAF',
                'card' => ['first_6digits' => '424242', 'last_4digits' => '4242', 'type' => 'visa',
                           'issuer' => 'TEST BANK', 'expiry' => '09/32'],
            ],
        ])]);

        $this->runSync($c);

        $metadata = $c->fresh()->payment_metadata;
        $this->assertSame(['last4' => '4242', 'brand' => 'VISA'], $metadata['card']);
        $this->assertStringNotContainsString('424242', json_encode($metadata));
        $this->assertStringNotContainsString('09/32', json_encode($metadata));
    }

    public function test_the_credit_is_idempotent(): void
    {
        $c = $this->pendingCardContribution(1000);
        $this->fakeVerification('successful', 1053);

        $this->runSync($c);
        $this->runSync($c);

        $this->assertSame(1000, Evenement::first()->montantCollecte);
        $this->assertSame(1, Evenement::first()->nombreContributions);
    }

    public function test_a_failed_or_cancelled_payment_is_marked_failed_without_credit(): void
    {
        foreach (['failed', 'cancelled'] as $status) {
            $c = $this->pendingCardContribution();
            $this->fakeVerification($status, 1053);

            $this->runSync($c);

            $this->assertSame('failed', $c->fresh()->statut, $status);
        }
        $this->assertSame(0, Evenement::sum('montantCollecte'));
    }

    public function test_a_payment_still_in_progress_stays_pending(): void
    {
        $c = $this->pendingCardContribution();
        $this->fakeVerification('pending', 1053);
        $this->runSync($c);
        $this->assertSame('pending', $c->fresh()->statut);

        // Flutterwave ne connaît pas encore la transaction (carte pas encore saisie).
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(
            ['status' => 'error', 'message' => 'No transaction was found for this id', 'data' => null], 404)]);
        $this->runSync($c);
        $this->assertSame('pending', $c->fresh()->statut);
    }

    public function test_an_amount_mismatch_is_refused_even_if_flutterwave_says_successful(): void
    {
        $c = $this->pendingCardContribution(1000);
        $this->fakeVerification('successful', 500);   // le fidèle a payé moins que demandé

        $this->runSync($c);

        $this->assertSame('failed', $c->fresh()->statut);
        $this->assertStringContainsString('différents', $c->fresh()->payment_metadata['rejection']);
        $this->assertSame(0, Evenement::first()->montantCollecte);
    }

    public function test_a_currency_mismatch_is_refused(): void
    {
        $c = $this->pendingCardContribution(1000);
        $this->fakeVerification('successful', 1053, 'USD');

        $this->runSync($c);

        $this->assertSame('failed', $c->fresh()->statut);
        $this->assertSame(0, Evenement::first()->montantCollecte);
    }

    public function test_the_stale_job_still_credits_a_card_payment_that_was_actually_paid(): void
    {
        $c = $this->pendingCardContribution(1000);
        $c->forceFill(['created_at' => now()->subHour()])->save();
        $this->fakeVerification('successful', 1053);

        (new ExpireStaleContributionsJob())->handle(app(PaymentGatewayRegistry::class), app(ContributionService::class));

        $this->assertSame('completed', $c->fresh()->statut);
        $this->assertSame(1000, Evenement::first()->montantCollecte);
    }

    public function test_the_stale_job_expires_an_abandoned_card_payment(): void
    {
        $c = $this->pendingCardContribution();
        $c->forceFill(['created_at' => now()->subHour()])->save();
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(
            ['status' => 'error', 'message' => 'No transaction was found for this id'], 404)]);

        (new ExpireStaleContributionsJob())->handle(app(PaymentGatewayRegistry::class), app(ContributionService::class));

        $this->assertSame('failed', $c->fresh()->statut);
    }

    // ─── Le Mobile Money n'est pas affecté ───────────────────────────────────

    public function test_registry_keeps_notchpay_for_legacy_and_mobile_money_contributions(): void
    {
        $registry = app(PaymentGatewayRegistry::class);

        $this->assertInstanceOf(NotchPayGateway::class, $registry->forContribution(new Contribution()));
        $this->assertInstanceOf(NotchPayGateway::class,
            $registry->forContribution(new Contribution(['paymentGateway' => 'notchpay'])));
    }
}
