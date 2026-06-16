<?php

namespace Tests\Feature\Payment;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

use App\Modules\Payment\Application\Jobs\ProcessCampayWebhookJob;
use App\Modules\Payment\Infrastructure\ExternalServices\CampayGateway;

class CampayWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_invalid_signature(): void
    {
        $gateway = Mockery::mock(CampayGateway::class);

        $gateway->shouldReceive('validateWebhookSignature')
            ->once()
            ->andReturn(false);

        $this->app->instance(CampayGateway::class, $gateway);

        $response = $this->postJson(
            '/api/v1/webhooks/campay',
            [
                'reference' => 'REF123',
                'status' => 'SUCCESSFUL',
            ],
            [
                'X-Campay-Signature' => 'invalid-signature',
            ]
        );

        $response->assertStatus(403);
    }

    public function test_webhook_requires_reference(): void
    {
        $gateway = Mockery::mock(CampayGateway::class);

        $gateway->shouldReceive('validateWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(CampayGateway::class, $gateway);

        $response = $this->postJson(
            '/api/v1/webhooks/campay',
            [
                'status' => 'SUCCESSFUL',
            ],
            [
                'X-Campay-Signature' => 'valid-signature',
            ]
        );

        $response->assertStatus(400);
    }

    public function test_valid_webhook_dispatches_job(): void
    {
        Queue::fake();

        $gateway = Mockery::mock(CampayGateway::class);

        $gateway->shouldReceive('validateWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(CampayGateway::class, $gateway);

        $payload = [
            'reference' => 'REF123',
            'status' => 'SUCCESSFUL',
        ];

        $response = $this->postJson(
            '/api/v1/webhooks/campay',
            $payload,
            [
                'X-Campay-Signature' => 'valid-signature',
            ]
        );

        $response->assertStatus(200);

        Queue::assertPushed(ProcessCampayWebhookJob::class);
    }
}