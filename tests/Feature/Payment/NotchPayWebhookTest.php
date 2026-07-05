<?php

namespace Tests\Feature\Payment;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

use App\Modules\Payment\Application\Jobs\ProcessPaymentWebhookJob;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;

class NotchPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_invalid_signature(): void
    {
        $gateway = Mockery::mock(NotchPayGateway::class);

        $gateway->shouldReceive('validateWebhookSignature')
            ->once()
            ->andReturn(false);

        $this->app->instance(NotchPayGateway::class, $gateway);

        $response = $this->postJson('/api/v1/webhooks/notchpay', [
            'event' => 'payment.complete',
            'data' => [
                'reference' => 'REF123',
                'status' => 'complete',
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_webhook_requires_reference(): void
    {
        $gateway = Mockery::mock(NotchPayGateway::class);

        $gateway->shouldReceive('validateWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(NotchPayGateway::class, $gateway);

        $response = $this->postJson('/api/v1/webhooks/notchpay', [
            'event' => 'payment.complete',
            'data' => [
                'status' => 'complete',
            ],
        ]);

        $response->assertStatus(400);
    }

    public function test_valid_webhook_dispatches_job(): void
    {
        Queue::fake();

        $gateway = Mockery::mock(NotchPayGateway::class);

        $gateway->shouldReceive('validateWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(NotchPayGateway::class, $gateway);

        $payload = [
            'event' => 'payment.complete',
            'data' => [
                'reference' => 'REF123',
                'status' => 'complete',
            ],
        ];

        $response = $this->postJson('/api/v1/webhooks/notchpay', $payload);

        $response->assertStatus(200);

        Queue::assertPushed(ProcessPaymentWebhookJob::class);
    }

    public function test_real_notchpay_hmac_signature_is_verified(): void
    {
        Queue::fake();

        config(['services.notchpay.webhook_hash' => 'test-hash-key']);
        config(['payment.notchpay.webhook_verify_signature' => true]);

        $payload = [
            'id' => 'whk.test',
            'event' => 'payment.complete',
            'data' => [
                'reference' => 'REF123',
                'status' => 'complete',
                'currency' => 'XAF',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, 'test-hash-key');

        $response = $this->withHeaders(['x-notch-signature' => $signature])
            ->postJson('/api/v1/webhooks/notchpay', $payload);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessPaymentWebhookJob::class);
    }
}
