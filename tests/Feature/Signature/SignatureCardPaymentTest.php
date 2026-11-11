<?php

namespace Tests\Feature\Signature;

use App\Models\Church;
use App\Models\PaymentTransaction;
use App\Models\Signature;
use App\Models\User;
use App\Modules\Payment\Application\Jobs\ExpireStalePaymentTransactionsJob;
use App\Modules\Payment\Application\Jobs\SyncPaymentStatusJob;
use App\Modules\Payment\Application\Services\PaymentGatewayRegistry;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Signature\Application\Services\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Tests Feature - Signature à distance (Sainte-Cène) payée par carte
 * bancaire via Flutterwave. Flutterwave est simulé via Http::fake().
 */
class SignatureCardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const URL      = '/api/v1/signatures/distance';
    private const CHECKOUT = 'https://checkout.flutterwave.test/pay/sig123';

    private User $user;
    private array $months;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        config([
            'payment.card.enabled'             => true,
            'payment.card.currency'            => 'XAF',
            'payment.card.fee_percent'         => 5.0,
            'services.flutterwave.secret_key'  => 'FLWSECK_TEST-xyz',
            'services.flutterwave.secret_hash' => 'secret-hash-test',
        ]);

        $church = Church::factory()->create();
        $this->user = User::factory()->create([
            'role' => 'paroissien', 'churchId' => $church->id, 'actif' => true, 'estCommuniant' => true,
        ]);
        Sanctum::actingAs($this->user);
        $this->months = [now()->subMonths(2)->format('Y-m'), now()->subMonth()->format('Y-m')];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function fakeCheckoutCreated(): void
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response([
            'status' => 'success', 'data' => ['link' => self::CHECKOUT],
        ])]);
    }

    private function cardPayload(int $amount = 1000, ?array $months = null): array
    {
        return ['months' => $months ?? $this->months, 'amount' => $amount, 'paymentMethod' => 'card'];
    }

    /** Lance un vrai paiement par carte (checkout simulé) et renvoie sa transaction. */
    private function initiatedCardTransaction(int $amount = 1000): PaymentTransaction
    {
        $this->fakeCheckoutCreated();
        $this->postJson(self::URL, $this->cardPayload($amount))->assertStatus(201);

        return PaymentTransaction::sole();
    }

    private function fakeVerification(string $status, mixed $amount, string $currency = 'XAF', ?array $card = null): void
    {
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
            'status' => 'success',
            'data' => array_filter([
                'id' => 4455, 'tx_ref' => 'x', 'status' => $status, 'amount' => $amount, 'currency' => $currency, 'card' => $card,
            ]),
        ])]);
    }

    private function runSync(PaymentTransaction $t): void
    {
        (new SyncPaymentStatusJob($t->id, $t->payment_reference))
            ->handle(app(PaymentGatewayRegistry::class), app(SignatureService::class));
    }

    // ─── Initiation ──────────────────────────────────────────────────────────

    public function test_card_signature_creates_the_hosted_checkout_and_never_touches_notchpay(): void
    {
        $this->fakeCheckoutCreated();
        $notch = Mockery::mock(NotchPayGateway::class);
        $notch->shouldNotReceive('initiateMobileMoney');
        $this->app->instance(NotchPayGateway::class, $notch);

        $this->postJson(self::URL, $this->cardPayload(1000))
            ->assertStatus(201)
            ->assertJsonPath('data.payment_method', 'card')
            ->assertJsonPath('data.checkout_url', self::CHECKOUT)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.months_count', 2)
            ->assertJsonPath('data.total_amount', 2106)   // 2 x 1000 = 2000 net -> 2000 / 0,95 = 2105,3 -> 2106
            ->assertJsonPath('data.fees_total', 106);

        $t = PaymentTransaction::sole();
        $this->assertSame('card', $t->paymentMethod);
        $this->assertSame('flutterwave', $t->paymentGateway);
        $this->assertNull($t->paymentOperator);
        $this->assertNull($t->paymentNumber);
        $this->assertSame(2000, $t->totalAmount);
        $this->assertSame(106, $t->fees);
        $this->assertSame(2106, $t->amountCharged);
        $this->assertStringStartsWith('SIGNME-SIG-', $t->payment_reference);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.flutterwave.com/v3/payments'
            && $r->data()['tx_ref'] === $t->payment_reference
            && $r->data()['amount'] === 2106
            && $r->data()['currency'] === 'XAF'
            && $r->data()['payment_options'] === 'card'
            && $r->data()['redirect_url'] === route('payment.callback')
            && $r->data()['customer']['email'] === $this->user->email);
        Queue::assertPushed(SyncPaymentStatusJob::class);
        $this->assertSame(0, Signature::count()); // aucune signature avant confirmation
    }

    public function test_the_client_cannot_impose_fees_or_a_total(): void
    {
        $this->fakeCheckoutCreated();

        $this->postJson(self::URL, [...$this->cardPayload(1000), 'fees' => 0, 'amountCharged' => 100])
            ->assertStatus(201)->assertJsonPath('data.total_amount', 2106);
    }

    public function test_card_payments_can_be_switched_off(): void
    {
        config(['payment.card.enabled' => false]);
        Http::fake();

        $this->postJson(self::URL, $this->cardPayload())->assertStatus(503);

        Http::assertNothingSent();
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_flutterwave_failure_creates_nothing_and_does_not_block_the_user(): void
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::sequence()
            ->push(['status' => 'error'], 500)
            ->push(['status' => 'success', 'data' => ['link' => self::CHECKOUT]])]);

        $this->postJson(self::URL, $this->cardPayload())->assertStatus(503);
        $this->assertSame(0, PaymentTransaction::count());

        $this->postJson(self::URL, $this->cardPayload())->assertStatus(201);
    }

    public function test_mobile_money_still_requires_operator_and_phone(): void
    {
        Http::fake();

        $this->postJson(self::URL, ['months' => $this->months, 'amount' => 1000, 'paymentMethod' => 'mobile_money'])
            ->assertStatus(422);
        $this->postJson(self::URL, ['months' => $this->months, 'amount' => 1000, 'paymentMethod' => 'paypal'])
            ->assertStatus(422);
    }

    public function test_card_amounts_are_limited_to_the_allowed_signature_amounts(): void
    {
        Http::fake();

        $this->postJson(self::URL, $this->cardPayload(1234))->assertStatus(422);
    }

    // ─── Suivi du statut ─────────────────────────────────────────────────────

    public function test_the_checkout_url_is_exposed_only_while_the_payment_is_pending(): void
    {
        $t = $this->initiatedCardTransaction();

        $this->getJson("/api/v1/signatures/transaction/{$t->id}/status")->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checkout_url', self::CHECKOUT);

        $this->fakeVerification('successful', $t->amountCharged);
        $this->runSync($t);

        $this->getJson("/api/v1/signatures/transaction/{$t->id}/status")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.checkout_url');
    }

    // ─── Webhook : simple déclencheur ────────────────────────────────────────

    public function test_webhook_signed_correctly_only_triggers_a_verification(): void
    {
        $t = $this->initiatedCardTransaction();
        Queue::fake();

        $this->postJson('/api/v1/webhooks/flutterwave',
            ['event' => 'charge.completed', 'data' => ['tx_ref' => $t->payment_reference, 'status' => 'successful']],
            ['verif-hash' => 'secret-hash-test'])->assertOk();

        Queue::assertPushed(SyncPaymentStatusJob::class,
            fn ($job) => $job->transactionId === $t->id && $job->paymentReference === $t->payment_reference);
        $this->assertSame('pending', $t->fresh()->status);
        $this->assertSame(0, Signature::count());
    }

    public function test_webhook_with_a_bad_signature_is_rejected(): void
    {
        $t = $this->initiatedCardTransaction();
        Queue::fake();

        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $t->payment_reference]],
            ['verif-hash' => 'mauvais'])->assertStatus(403);

        Queue::assertNotPushed(SyncPaymentStatusJob::class);
    }

    // ─── Vérification auprès de Flutterwave : seule source de vérité ─────────

    public function test_a_verified_payment_creates_one_card_signature_per_month(): void
    {
        $t = $this->initiatedCardTransaction(1000);
        $this->fakeVerification('successful', 2106, 'XAF', ['last_4digits' => '4242', 'type' => 'visa', 'first_6digits' => '424242']);

        $this->runSync($t);

        $this->assertSame('completed', $t->fresh()->status);
        $signatures = Signature::where('userId', $this->user->id)->orderBy('month')->get();
        $this->assertCount(2, $signatures);
        foreach ($signatures as $s) {
            $this->assertSame('completed', $s->status);
            $this->assertSame('distance', $s->signatureType);
            $this->assertSame(Signature::PAYMENT_CARTE, $s->paymentMethod);
            $this->assertNull($s->phoneNumber);
            $this->assertSame(1000, $s->amount);
            $this->assertSame(53, $s->fees);   // 106 de frais / 2 mois
        }
        $this->assertSame(['last4' => '4242', 'brand' => 'VISA'], $t->fresh()->payment_metadata['card']);
        $this->assertStringNotContainsString('424242', json_encode($t->fresh()->payment_metadata));
    }

    public function test_the_confirmation_is_idempotent(): void
    {
        $t = $this->initiatedCardTransaction(1000);
        $this->fakeVerification('successful', 2106);

        $this->runSync($t);
        $this->runSync($t);

        $this->assertSame(2, Signature::count());
    }

    public function test_a_failed_or_cancelled_payment_creates_no_signature(): void
    {
        foreach (['failed', 'cancelled'] as $status) {
            PaymentTransaction::query()->delete();
            $t = $this->initiatedCardTransaction(1000);
            $this->fakeVerification($status, 2106);

            $this->runSync($t);

            $this->assertSame('failed', $t->fresh()->status, $status);
        }
        $this->assertSame(0, Signature::count());
    }

    public function test_an_amount_or_currency_mismatch_is_refused_even_if_successful(): void
    {
        $t = $this->initiatedCardTransaction(1000);
        $this->fakeVerification('successful', 1000);   // a payé moins que demandé
        $this->runSync($t);
        $this->assertSame('failed', $t->fresh()->status);

        PaymentTransaction::query()->delete();
        $t = $this->initiatedCardTransaction(1000);
        $this->fakeVerification('successful', 2106, 'USD');
        $this->runSync($t);
        $this->assertSame('failed', $t->fresh()->status);

        $this->assertSame(0, Signature::count());
    }

    public function test_a_payment_still_in_progress_stays_pending(): void
    {
        $t = $this->initiatedCardTransaction();
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(
            ['status' => 'error', 'message' => 'No transaction was found for this id'], 404)]);

        $this->runSync($t);

        $this->assertSame('pending', $t->fresh()->status);
    }

    public function test_the_stale_job_credits_a_payment_that_was_actually_made(): void
    {
        $t = $this->initiatedCardTransaction(1000);
        $t->forceFill(['created_at' => now()->subHour()])->save();
        $this->fakeVerification('successful', 2106);

        (new ExpireStalePaymentTransactionsJob())->handle(app(PaymentGatewayRegistry::class), app(SignatureService::class));

        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame(2, Signature::count());
    }

    public function test_the_stale_job_expires_an_abandoned_card_payment(): void
    {
        $t = $this->initiatedCardTransaction();
        $t->forceFill(['created_at' => now()->subHour()])->save();
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(
            ['status' => 'error', 'message' => 'No transaction was found for this id'], 404)]);

        (new ExpireStalePaymentTransactionsJob())->handle(app(PaymentGatewayRegistry::class), app(SignatureService::class));

        $this->assertSame('failed', $t->fresh()->status);
        $this->assertSame(0, Signature::count());
    }

    // ─── Mes transactions ────────────────────────────────────────────────────

    public function test_a_card_signature_appears_in_my_transactions_with_the_card(): void
    {
        $t = $this->initiatedCardTransaction(1000);
        $this->fakeVerification('successful', 2106, 'XAF', ['last_4digits' => '4242', 'type' => 'visa']);
        $this->runSync($t);

        $this->getJson('/api/v1/transactions')->assertOk()
            ->assertJsonPath('data.0.type', 'signature')
            ->assertJsonPath('data.0.paymentMethod', 'card')
            ->assertJsonPath('data.0.paymentLabel', 'Carte bancaire')
            ->assertJsonPath('data.0.cardBrand', 'VISA')
            ->assertJsonPath('data.0.cardLast4', '4242')
            ->assertJsonPath('data.0.montantTotal', 2106)
            ->assertJsonPath('data.0.statut', 'completed');
    }
}
