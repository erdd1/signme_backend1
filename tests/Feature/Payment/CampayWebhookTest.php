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

        $response = $this->postJson('/api/v1/webhooks/campay', [
            'reference' => 'REF123',
            'status' => 'SUCCESSFUL',
            'signature' => 'invalid.jwt.token',
        ]);

        $response->assertStatus(403);
    }

    public function test_webhook_requires_reference(): void
    {
        $gateway = Mockery::mock(CampayGateway::class);

        $gateway->shouldReceive('validateWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(CampayGateway::class, $gateway);

        $response = $this->postJson('/api/v1/webhooks/campay', [
            'status' => 'SUCCESSFUL',
            'signature' => 'valid.jwt.token',
        ]);

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
            'signature' => 'valid.jwt.token',
        ];

        $response = $this->postJson('/api/v1/webhooks/campay', $payload);

        $response->assertStatus(200);

        Queue::assertPushed(ProcessCampayWebhookJob::class);
    }

    public function test_real_campay_jwt_signature_is_verified(): void
    {
        Queue::fake();

        config(['campay.webhook_secret' => 'test-secret']);
        config(['payment.campay.webhook_verify_signature' => true]);

        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'app' => 'eec signcard', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iat' => now()->timestamp,
            'nbf' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
            'source' => 'CamPay',
        ]));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', "{$header}.{$claims}", 'test-secret', true));
        $jwt = "{$header}.{$claims}.{$signature}";

        $response = $this->postJson('/api/v1/webhooks/campay', [
            'reference' => 'REF123',
            'status' => 'SUCCESSFUL',
            'signature' => $jwt,
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessCampayWebhookJob::class);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}