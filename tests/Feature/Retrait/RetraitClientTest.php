<?php

namespace Tests\Feature\Retrait;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\Retrait;
use App\Models\RetraitBeneficiaire;
use App\Models\User;
use App\Modules\Payment\Infrastructure\ExternalServices\NotchPayGateway;
use App\Modules\Retrait\Application\Jobs\ProcessRetraitWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Tests Feature — Module Retrait (payout NotchPay, pasteur/trésorier).
 *
 * Couvre :
 *  - RBAC (seuls pasteur/trésorier)
 *  - Solde interne (jamais lu depuis NotchPay::Balance, global à la plateforme)
 *  - Enregistrement / remplacement du compte de retrait (Beneficiary NotchPay)
 *  - Initiation d'un retrait (validation montant min, solde insuffisant, succès)
 *  - Historique paginé, scopé à l'église
 *  - Webhook `transfer.*` routé vers ProcessRetraitWebhookJob
 */
class RetraitClientTest extends TestCase
{
    use RefreshDatabase;

    private function church(): Church
    {
        return Church::factory()->create();
    }

    private function pasteur(Church $church): User
    {
        return User::factory()->create(['role' => 'pasteur', 'churchId' => $church->id, 'actif' => true]);
    }

    private function tresorier(Church $church): User
    {
        return User::factory()->create(['role' => 'tresorier', 'churchId' => $church->id, 'actif' => true]);
    }

    private function seedContribution(Church $church, User $auteur, int $montant = 10000): void
    {
        $evenement = Evenement::create([
            'uuid'        => (string) Str::uuid(),
            'churchId'    => $church->id,
            'auteurId'    => $auteur->id,
            'titre'       => 'Collecte test',
            'description' => str_repeat('a', 25),
            'statut'      => Evenement::STATUT_ACTIF,
            'dateDebut'   => now(),
            'publishedAt' => now(),
        ]);

        Contribution::create([
            'uuid'            => (string) Str::uuid(),
            'evenementId'     => $evenement->id,
            'userId'          => $auteur->id,
            'montant'         => $montant,
            'frais'           => 0,
            'montantTotal'    => $montant,
            'paymentOperator' => 'ORANGE MONEY',
            'paymentNumber'   => '699000001',
            'statut'          => Contribution::STATUT_COMPLETED,
        ]);
    }

    private function mockGatewayForBeneficiaire(string $beneficiaryId = 'ben_test_123'): void
    {
        $gateway = Mockery::mock(NotchPayGateway::class)->makePartial();
        $gateway->shouldReceive('createBeneficiary')
            ->andReturn(['id' => $beneficiaryId, 'raw' => ['id' => $beneficiaryId]]);
        $this->app->instance(NotchPayGateway::class, $gateway);
    }

    private function beneficiaireActif(User $user): RetraitBeneficiaire
    {
        return RetraitBeneficiaire::create([
            'userId'                  => $user->id,
            'churchId'                => $user->churchId,
            'channel'                 => RetraitBeneficiaire::CHANNEL_ORANGE,
            'account_number'          => '237699000001',
            'name'                    => $user->nom,
            'notchpay_beneficiary_id' => 'ben_existing',
            'is_active'               => true,
        ]);
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    public function test_guest_cannot_access_retraits(): void
    {
        $this->getJson('/api/v1/retraits/solde')->assertStatus(401);
        $this->getJson('/api/v1/retraits')->assertStatus(401);
    }

    public function test_paroissien_cannot_access_retraits(): void
    {
        $church = $this->church();
        $paroissien = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id, 'actif' => true]);
        Sanctum::actingAs($paroissien);

        $this->getJson('/api/v1/retraits/solde')->assertStatus(403);
    }

    public function test_secretaire_cannot_access_retraits(): void
    {
        $church = $this->church();
        $secretaire = User::factory()->create(['role' => 'secretaire', 'churchId' => $church->id, 'actif' => true]);
        Sanctum::actingAs($secretaire);

        $this->getJson('/api/v1/retraits/solde')->assertStatus(403);
    }

    // =========================================================================
    // SOLDE
    // =========================================================================

    public function test_solde_correspond_au_calcul_interne(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->seedContribution($church, $pasteur, 10000);

        Sanctum::actingAs($pasteur);

        $this->getJson('/api/v1/retraits/solde')
             ->assertStatus(200)
             ->assertJsonPath('data.solde', 10000);
    }

    public function test_solde_deduit_les_retraits_en_cours(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->seedContribution($church, $pasteur, 10000);
        $beneficiaire = $this->beneficiaireActif($pasteur);

        Retrait::create([
            'churchId' => $church->id,
            'userId' => $pasteur->id,
            'retrait_beneficiaire_id' => $beneficiaire->id,
            'montant_demande' => 4000,
            'frais' => 0,
            'montant_net' => 4000,
            'statut' => Retrait::STATUT_PENDING,
            'reference' => 'RETRAIT-TEST-1',
        ]);

        Sanctum::actingAs($pasteur);

        $this->getJson('/api/v1/retraits/solde')
             ->assertStatus(200)
             ->assertJsonPath('data.solde', 6000);
    }

    // =========================================================================
    // BENEFICIAIRE
    // =========================================================================

    public function test_aucun_beneficiaire_par_defaut(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        Sanctum::actingAs($pasteur);

        $this->getJson('/api/v1/retraits/beneficiaire')
             ->assertStatus(200)
             ->assertJsonPath('data', null);
    }

    public function test_enregistrement_beneficiaire(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->mockGatewayForBeneficiaire('ben_new_1');
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits/beneficiaire', [
            'operator' => 'ORANGE MONEY',
            'phone'    => '699112233',
        ])->assertStatus(201)
          ->assertJsonPath('data.operator', 'Orange Money');

        $this->assertDatabaseHas('retrait_beneficiaires', [
            'userId'                  => $pasteur->id,
            'notchpay_beneficiary_id' => 'ben_new_1',
            'is_active'               => true,
        ]);
    }

    public function test_remplacement_beneficiaire_desactive_lancien(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $ancien  = $this->beneficiaireActif($pasteur);
        $this->mockGatewayForBeneficiaire('ben_new_2');
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits/beneficiaire', [
            'operator' => 'MTN MOBILE MONEY',
            'phone'    => '677112233',
        ])->assertStatus(201);

        $this->assertDatabaseHas('retrait_beneficiaires', ['id' => $ancien->id, 'is_active' => false]);
        $this->assertDatabaseHas('retrait_beneficiaires', [
            'notchpay_beneficiary_id' => 'ben_new_2',
            'is_active'               => true,
        ]);
    }

    public function test_beneficiaire_operateur_invalide_rejete(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits/beneficiaire', [
            'operator' => 'AIRTEL MONEY',
            'phone'    => '699112233',
        ])->assertStatus(422);
    }

    // =========================================================================
    // INITIATION D'UN RETRAIT
    // =========================================================================

    public function test_retrait_sans_beneficiaire_est_rejete(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->seedContribution($church, $pasteur, 10000);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits', ['montant' => 2000])->assertStatus(400);
    }

    public function test_retrait_montant_inferieur_au_minimum_rejete(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->beneficiaireActif($pasteur);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits', ['montant' => 500])->assertStatus(422);
    }

    public function test_retrait_solde_insuffisant_rejete(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->seedContribution($church, $pasteur, 1000);
        $this->beneficiaireActif($pasteur);
        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits', ['montant' => 5000])->assertStatus(400);
    }

    public function test_retrait_reussi(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->seedContribution($church, $pasteur, 10000);
        $this->beneficiaireActif($pasteur);

        $gateway = Mockery::mock(NotchPayGateway::class)->makePartial();
        $gateway->shouldReceive('initiateTransfer')->once()->andReturn([
            'id'     => 'trf_123',
            'status' => 'pending',
        ]);
        $gateway->shouldReceive('mapStatus')->with('pending')->andReturn('pending');
        $this->app->instance(NotchPayGateway::class, $gateway);

        Sanctum::actingAs($pasteur);

        $response = $this->postJson('/api/v1/retraits', ['montant' => 4000]);
        $response->assertStatus(201)
                 ->assertJsonPath('data.montantDemande', 4000)
                 ->assertJsonPath('data.statut', 'processing');

        $this->assertDatabaseHas('retraits', [
            'churchId'        => $church->id,
            'montant_demande' => 4000,
            'notchpay_transfer_id' => 'trf_123',
        ]);
    }

    public function test_retrait_echec_gateway_libere_le_solde(): void
    {
        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->seedContribution($church, $pasteur, 10000);
        $this->beneficiaireActif($pasteur);

        $gateway = Mockery::mock(NotchPayGateway::class)->makePartial();
        $gateway->shouldReceive('initiateTransfer')->once()->andThrow(new \Exception('NotchPay indisponible'));
        $this->app->instance(NotchPayGateway::class, $gateway);

        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits', ['montant' => 4000])
             ->assertStatus(201)
             ->assertJsonPath('data.statut', 'failed');

        $this->getJson('/api/v1/retraits/solde')
             ->assertStatus(200)
             ->assertJsonPath('data.solde', 10000);
    }

    public function test_retrait_deduit_les_frais_configures(): void
    {
        config(['payment.notchpay.transfer_fee_percent' => 10]);

        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $this->seedContribution($church, $pasteur, 10000);
        $this->beneficiaireActif($pasteur);

        $gateway = Mockery::mock(NotchPayGateway::class)->makePartial();
        $gateway->shouldReceive('initiateTransfer')->once()->andReturn(['id' => 'trf_x', 'status' => 'complete']);
        $gateway->shouldReceive('mapStatus')->with('complete')->andReturn('completed');
        $this->app->instance(NotchPayGateway::class, $gateway);

        Sanctum::actingAs($pasteur);

        $this->postJson('/api/v1/retraits', ['montant' => 5000])
             ->assertStatus(201)
             ->assertJsonPath('data.frais', 500)
             ->assertJsonPath('data.montantNet', 4500)
             ->assertJsonPath('data.statut', 'completed');
    }

    // =========================================================================
    // HISTORIQUE
    // =========================================================================

    public function test_historique_scope_par_eglise(): void
    {
        $church1 = $this->church();
        $church2 = $this->church();
        $pasteur1 = $this->pasteur($church1);
        $pasteur2 = $this->pasteur($church2);
        $benef1 = $this->beneficiaireActif($pasteur1);

        Retrait::create([
            'churchId' => $church1->id, 'userId' => $pasteur1->id,
            'retrait_beneficiaire_id' => $benef1->id,
            'montant_demande' => 2000, 'frais' => 0, 'montant_net' => 2000,
            'statut' => Retrait::STATUT_COMPLETED, 'reference' => 'RETRAIT-A',
        ]);

        Sanctum::actingAs($pasteur2);
        $response = $this->getJson('/api/v1/retraits');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));

        Sanctum::actingAs($pasteur1);
        $response = $this->getJson('/api/v1/retraits');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_tresorier_voit_historique_de_son_eglise(): void
    {
        $church = $this->church();
        $pasteur = $this->pasteur($church);
        $tresorier = $this->tresorier($church);
        $benef = $this->beneficiaireActif($pasteur);

        Retrait::create([
            'churchId' => $church->id, 'userId' => $pasteur->id,
            'retrait_beneficiaire_id' => $benef->id,
            'montant_demande' => 3000, 'frais' => 0, 'montant_net' => 3000,
            'statut' => Retrait::STATUT_COMPLETED, 'reference' => 'RETRAIT-B',
        ]);

        Sanctum::actingAs($tresorier);
        $response = $this->getJson('/api/v1/retraits');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // =========================================================================
    // WEBHOOK
    // =========================================================================

    public function test_webhook_retrait_route_vers_le_bon_job(): void
    {
        Queue::fake();

        $church  = $this->church();
        $pasteur = $this->pasteur($church);
        $benef   = $this->beneficiaireActif($pasteur);

        Retrait::create([
            'churchId' => $church->id, 'userId' => $pasteur->id,
            'retrait_beneficiaire_id' => $benef->id,
            'montant_demande' => 2000, 'frais' => 0, 'montant_net' => 2000,
            'statut' => Retrait::STATUT_PROCESSING, 'reference' => 'RETRAIT-WEBHOOK-1',
        ]);

        $gateway = Mockery::mock(NotchPayGateway::class)->makePartial();
        $gateway->shouldReceive('validateWebhookSignature')->once()->andReturn(true);
        $this->app->instance(NotchPayGateway::class, $gateway);

        $this->postJson('/api/v1/webhooks/notchpay', [
            'event' => 'transfer.complete',
            'data'  => ['reference' => 'RETRAIT-WEBHOOK-1', 'status' => 'complete'],
        ])->assertStatus(200);

        Queue::assertPushed(ProcessRetraitWebhookJob::class);
    }
}
