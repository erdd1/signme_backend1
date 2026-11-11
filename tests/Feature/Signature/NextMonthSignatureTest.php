<?php

namespace Tests\Feature\Signature;

use App\Models\AncienDesignation;
use App\Models\Church;
use App\Models\Signature;
use App\Models\User;
use App\Modules\Signature\Application\Services\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Règle « mois prochain » : on peut signer pour le mois courant, les mois
 * passés (contraintes habituelles) ET le mois prochain — jamais au-delà.
 * Octobre : novembre oui, décembre non. Décembre 2026 : janvier 2027 oui,
 * février 2027 non. Valable à distance et en présentiel, pour tous les rôles.
 */
class NextMonthSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

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
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response([
            'status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/x'],
        ])]);
        $this->church = Church::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function chretien(string $role = 'paroissien'): User
    {
        return User::factory()->create([
            'role' => $role, 'churchId' => $this->church->id, 'actif' => true, 'estCommuniant' => true,
        ]);
    }

    private function distance(array $months): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/signatures/distance', [
            'months' => $months, 'amount' => 1000, 'paymentMethod' => 'card',
        ]);
    }

    // ─── Règle unique ────────────────────────────────────────────────────────

    public function test_the_last_signable_month_is_next_month_with_year_rollover_and_no_day_overflow(): void
    {
        foreach ([
            '2026-10-15' => '2026-11',
            '2026-12-15' => '2027-01',   // décembre → janvier de l'année suivante
            '2026-01-31' => '2026-02',   // pas de débordement vers mars un 31
            '2026-05-31' => '2026-06',
        ] as $jour => $attendu) {
            Carbon::setTestNow($jour);
            $this->assertSame($attendu, SignatureService::dernierMoisSignable(), $jour);
        }
    }

    // ─── Signature à distance ────────────────────────────────────────────────

    public function test_in_october_a_christian_can_sign_november_but_not_december(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        Sanctum::actingAs($this->chretien());

        $this->distance(['2026-11'])->assertStatus(201);
        $this->distance(['2026-12'])->assertStatus(400);
        $this->distance(['2026-10', '2026-12'])->assertStatus(400);   // un seul mois trop loin suffit
    }

    public function test_in_december_a_christian_can_sign_january_of_next_year_but_not_february(): void
    {
        Carbon::setTestNow('2026-12-15 10:00:00');
        Sanctum::actingAs($this->chretien());

        $this->distance(['2027-01'])->assertStatus(201);
        $this->distance(['2027-02'])->assertStatus(400);
    }

    public function test_the_refusal_names_the_limit(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        Sanctum::actingAs($this->chretien());

        $this->distance(['2026-12'])->assertStatus(400)
            ->assertJsonPath('message', "Impossible de signer pour le mois 2026-12 : vous pouvez signer jusqu'au mois prochain (2026-11) au plus.");
    }

    public function test_next_month_works_for_every_role(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        foreach (['paroissien', 'ancien', 'tresorier', 'secretaire', 'pasteur'] as $role) {
            Sanctum::actingAs($this->chretien($role));
            $this->distance(['2026-11'])->assertStatus(201);
        }
    }

    public function test_existing_constraints_still_apply_to_next_month(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $user = $this->chretien();
        Sanctum::actingAs($user);

        // Déjà signé : refusé même pour le mois prochain.
        Signature::create([
            'userId' => $user->id, 'nomParoissien' => $user->nom, 'churchId' => $this->church->id,
            'monthIndex' => 10, 'month' => '2026-11', 'year' => 2026, 'amount' => 1000, 'fees' => 0,
            'paymentMethod' => 'ESPECES', 'signatureType' => 'presentiel', 'status' => 'completed',
        ]);
        $this->distance(['2026-11'])->assertStatus(409);

        // Non communiant : toujours bloqué.
        $non = $this->chretien();
        $non->estCommuniant = false;
        $non->save();
        Sanctum::actingAs($non);
        $this->distance(['2026-12'])->assertStatus(403);
    }

    public function test_the_current_month_and_past_months_still_work(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        Sanctum::actingAs($this->chretien());

        $this->distance(['2026-10'])->assertStatus(201);
        $this->distance(['2026-08', '2026-09'])->assertStatus(201);
    }

    public function test_ma_carte_exposes_the_limit_and_offers_next_month(): void
    {
        Carbon::setTestNow('2026-12-15 10:00:00');
        Sanctum::actingAs($this->chretien());

        $this->getJson('/api/v1/signatures/ma-carte?year=2027')->assertOk()
            ->assertJsonPath('data.dernier_mois_signable', '2027-01')
            ->assertJsonPath('data.mois_disponibles', ['2027-01']);
        $this->getJson('/api/v1/signatures/ma-carte?year=2026')->assertOk()
            ->assertJsonPath('data.dernier_mois_signable', '2027-01');
    }

    // ─── Présentiel ──────────────────────────────────────────────────────────

    public function test_in_presentiel_a_pasteur_can_sign_next_month_for_a_member_but_not_two_months_ahead(): void
    {
        Carbon::setTestNow('2026-12-15 10:00:00');
        $membre = $this->chretien();
        Sanctum::actingAs($this->chretien('pasteur'));

        $this->postJson('/api/v1/signatures/presentiel', ['userId' => $membre->id, 'months' => ['2027-02'], 'amount' => 1000])
            ->assertStatus(400);
        $this->postJson('/api/v1/signatures/presentiel', ['userId' => $membre->id, 'months' => ['2027-01'], 'amount' => 1000])
            ->assertStatus(201);

        $this->assertDatabaseHas('signatures', ['userId' => $membre->id, 'month' => '2027-01', 'signatureType' => 'presentiel']);
    }

    public function test_in_presentiel_an_ancien_still_needs_his_designation_for_next_month(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $membre = $this->chretien();
        $ancien = $this->chretien('ancien');
        $ancien->est_de_service = true;
        $ancien->save();
        Sanctum::actingAs($ancien);

        // Sans désignation pour novembre : refus habituel (contrainte inchangée).
        $this->postJson('/api/v1/signatures/presentiel', ['userId' => $membre->id, 'months' => ['2026-11'], 'amount' => 1000])
            ->assertStatus(403);
        // Au-delà du mois prochain : refusé avant même la désignation.
        $this->postJson('/api/v1/signatures/presentiel', ['userId' => $membre->id, 'months' => ['2026-12'], 'amount' => 1000])
            ->assertStatus(400);
    }

    public function test_in_presentiel_a_designated_ancien_can_sign_next_month(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00'); // jeudi
        $membre = $this->chretien();
        $ancien = $this->chretien('ancien');
        $ancien->est_de_service = true;
        $ancien->save();
        AncienDesignation::create([
            'ancienId' => $ancien->id, 'egliseId' => $this->church->id, 'mois' => 11, 'annee' => 2026,
            'designePar' => $this->chretien('pasteur')->id, 'designeParNom' => 'Pasteur',
        ]);
        Sanctum::actingAs($ancien);

        $this->postJson('/api/v1/signatures/presentiel', ['userId' => $membre->id, 'months' => ['2026-11'], 'amount' => 1000])
            ->assertStatus(201);
    }
}
