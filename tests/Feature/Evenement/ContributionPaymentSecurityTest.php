<?php

namespace Tests\Feature\Evenement;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\User;
use App\Modules\Evenement\Application\Jobs\ProcessEvenementContributionWebhookJob;
use App\Modules\Evenement\Application\Services\ContributionService;
use App\Modules\Payment\Application\Jobs\ProcessPaymentWebhookJob;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ContributionPaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function church(): Church
    {
        return Church::factory()->create();
    }

    private function activeEvenement(Church $church, User $auteur): Evenement
    {
        return Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Excellence scolaire 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);
    }

    private function mockGateway(string $reference = 'CONTRIB-REF-123'): void
    {
        $gateway = Mockery::mock(NotchPayGateway::class);
        $gateway->shouldReceive('initiateMobileMoney')->once()->andReturn($reference);
        $this->app->instance(NotchPayGateway::class, $gateway);
    }

    public function test_cannot_contribute_to_a_non_active_evenement(): void
    {
        $church = $this->church();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);

        $evenement = Evenement::create([
            'uuid'        => \Illuminate\Support\Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Aide orphelins 2026',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_TERMINE,
            'dateDebut'   => now(),
            'termineAt'   => now(),
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", [
                'montant'         => 700,
                'paymentMethod'   => 'mobile_money',
                'paymentOperator' => 'ORANGE MONEY',
                'paymentNumber'   => '699112233',
            ]);

        $response->assertStatus(403);
    }

    public function test_montant_out_of_grid_is_rejected(): void
    {
        $church = $this->church();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->activeEvenement($church, $auteur);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", [
                'montant'         => 505, // pas multiple de 50
                'paymentMethod'   => 'mobile_money',
                'paymentOperator' => 'ORANGE MONEY',
                'paymentNumber'   => '699112233',
            ]);

        $response->assertStatus(422);
    }

    public function test_fees_are_recalculated_server_side_and_client_cannot_send_fees(): void
    {
        // Queue::fake() évite toute connexion Redis réelle pour la planification
        // du polling de secours (ContributionPollingDispatcher) pendant le test.
        Queue::fake();

        $church = $this->church();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->activeEvenement($church, $auteur);

        $this->mockGateway();

        $token = $paroissien->createToken('test')->plainTextToken;

        // Le client tente d'envoyer des frais falsifiés : ignorés (champ non accepté par le FormRequest)
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", [
                'montant'         => 700,
                'frais'           => 1, // falsifié, doit être ignoré
                'paymentMethod'   => 'mobile_money',
                'paymentOperator' => 'ORANGE MONEY',
                'paymentNumber'   => '699112233',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.montant', 700);
        $response->assertJsonPath('data.frais', 50);
        $response->assertJsonPath('data.montantTotal', 750);

        $this->assertDatabaseHas('contributions', [
            'evenementId' => $evenement->id,
            'montant'     => 700,
            'frais'       => 50,
            'montantTotal'=> 750,
        ]);
    }

    public function test_cannot_have_two_pending_contributions_at_once(): void
    {
        $church = $this->church();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->activeEvenement($church, $auteur);

        Contribution::create([
            'uuid'            => \Illuminate\Support\Str::uuid(),
            'evenementId'     => $evenement->id,
            'userId'          => $paroissien->id,
            'montant'         => 500,
            'frais'           => 50,
            'montantTotal'    => 550,
            'paymentOperator' => 'ORANGE MONEY',
            'paymentNumber'   => '699112233',
            'statut'          => Contribution::STATUT_PENDING,
        ]);

        $token = $paroissien->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/evenements/{$evenement->uuid}/contributions", [
                'montant'         => 1000,
                'paymentMethod'   => 'mobile_money',
                'paymentOperator' => 'MTN MOBILE MONEY',
                'paymentNumber'   => '677112233',
            ]);

        $response->assertStatus(409);
    }

    public function test_webhook_notification_is_idempotent_and_credits_evenement_once(): void
    {
        $church = $this->church();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->activeEvenement($church, $auteur);

        $contribution = Contribution::create([
            'uuid'              => \Illuminate\Support\Str::uuid(),
            'evenementId'       => $evenement->id,
            'userId'            => $paroissien->id,
            'montant'           => 700,
            'frais'             => 50,
            'montantTotal'      => 750,
            'paymentOperator'   => 'ORANGE MONEY',
            'paymentNumber'     => '699112233',
            'payment_reference' => 'CONTRIB-IDEMPOTENT-1',
            'statut'            => Contribution::STATUT_PENDING,
        ]);

        $payload = ['reference' => 'CONTRIB-IDEMPOTENT-1', 'status' => 'complete'];

        /** @var ContributionService $service */
        $service = app(ContributionService::class);

        // Le webhook et le job de polling peuvent tous deux notifier : la 2e fois doit être un no-op
        $service->processPaymentNotification($payload);
        $service->processPaymentNotification($payload);

        $evenement->refresh();
        $this->assertSame(700, $evenement->montantCollecte);
        $this->assertSame(1, $evenement->nombreContributions);

        $contribution->refresh();
        $this->assertSame(Contribution::STATUT_COMPLETED, $contribution->statut);
    }

    public function test_evenement_still_processes_a_payment_already_launched_even_if_now_terminated(): void
    {
        $church = $this->church();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id]);
        $evenement = $this->activeEvenement($church, $auteur);

        $contribution = Contribution::create([
            'uuid'              => \Illuminate\Support\Str::uuid(),
            'evenementId'       => $evenement->id,
            'userId'            => $paroissien->id,
            'montant'           => 1000,
            'frais'             => 100,
            'montantTotal'      => 1100,
            'paymentOperator'   => 'ORANGE MONEY',
            'paymentNumber'     => '699112233',
            'payment_reference' => 'CONTRIB-LATE-1',
            'statut'            => Contribution::STATUT_PENDING,
        ]);

        // L'événement se termine pendant que le paiement est en vol
        $evenement->update(['statut' => Evenement::STATUT_TERMINE, 'termineAt' => now()]);

        app(ContributionService::class)->processPaymentNotification([
            'reference' => 'CONTRIB-LATE-1',
            'status'    => 'complete',
        ]);

        $contribution->refresh();
        $this->assertSame(Contribution::STATUT_COMPLETED, $contribution->statut);

        $evenement->refresh();
        $this->assertSame(1000, $evenement->montantCollecte);
    }

    public function test_webhook_routes_contribution_reference_to_contribution_job(): void
    {
        Queue::fake();

        $church = $this->church();
        $auteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id]);
        $evenement = $this->activeEvenement($church, $auteur);

        Contribution::create([
            'uuid'              => \Illuminate\Support\Str::uuid(),
            'evenementId'       => $evenement->id,
            'userId'            => $auteur->id,
            'montant'           => 500,
            'frais'             => 50,
            'montantTotal'      => 550,
            'paymentOperator'   => 'ORANGE MONEY',
            'paymentNumber'     => '699112233',
            'payment_reference' => 'CONTRIB-ROUTE-1',
            'statut'            => Contribution::STATUT_PENDING,
        ]);

        $gateway = Mockery::mock(NotchPayGateway::class);
        $gateway->shouldReceive('validateWebhookSignature')->once()->andReturn(true);
        $this->app->instance(NotchPayGateway::class, $gateway);

        $response = $this->postJson('/api/v1/webhooks/notchpay', [
            'event' => 'payment.complete',
            'data' => [
                'reference' => 'CONTRIB-ROUTE-1',
                'status'    => 'complete',
            ],
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(ProcessEvenementContributionWebhookJob::class);
        Queue::assertNotPushed(ProcessPaymentWebhookJob::class);
    }
}
