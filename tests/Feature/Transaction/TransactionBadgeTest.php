<?php

namespace Tests\Feature\Transaction;

use App\Models\Church;
use App\Models\Contribution;
use App\Models\Evenement;
use App\Models\PaymentTransaction;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Badge de l'onglet Services : nouvelles transactions EFFECTUÉES (confirmées)
 * depuis la dernière consultation de « Mes transactions ».
 */
class TransactionBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;
    private User $me;
    private Evenement $evenement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->church = Church::factory()->create();
        $pasteur = User::factory()->create(['role' => 'pasteur', 'churchId' => $this->church->id, 'actif' => true]);
        $this->me = User::factory()->create([
            'role' => 'paroissien', 'churchId' => $this->church->id, 'actif' => true, 'estCommuniant' => true,
        ]);
        $this->evenement = Evenement::create([
            'uuid' => (string) Str::uuid(), 'churchId' => $this->church->id, 'auteurId' => $pasteur->id,
            'titre' => 'Collecte', 'description' => str_repeat('a', 25), 'statut' => Evenement::STATUT_ACTIF,
            'dateDebut' => now(), 'publishedAt' => now(),
        ]);
        $this->me->forceFill(['last_read_transactions_at' => now()->subHour()])->save();
        Sanctum::actingAs($this->me);
    }

    private function badge(): int
    {
        return $this->getJson('/api/v1/transactions/non-lues')->assertOk()->json('data.count');
    }

    private function contribution(string $statut = 'completed', ?User $user = null, array $extra = []): Contribution
    {
        return Contribution::create($extra + [
            'uuid' => (string) Str::uuid(), 'evenementId' => $this->evenement->id, 'userId' => ($user ?? $this->me)->id,
            'montant' => 1000, 'frais' => 100, 'montantTotal' => 1100, 'paymentMethod' => 'mobile_money',
            'paymentGateway' => 'notchpay', 'payment_reference' => 'REF-' . Str::random(8), 'statut' => $statut,
        ]);
    }

    private function especes(string $mois, ?\DateTimeInterface $at = null): Signature
    {
        $s = Signature::create([
            'userId' => $this->me->id, 'nomParoissien' => $this->me->nom, 'churchId' => $this->church->id,
            'monthIndex' => (int) substr($mois, 5, 2) - 1, 'month' => $mois, 'year' => (int) substr($mois, 0, 4),
            'amount' => 1000, 'fees' => 0, 'paymentMethod' => Signature::PAYMENT_ESPECES, 'signatureType' => 'presentiel',
            'status' => 'completed', 'ancienId' => null,
        ]);
        if ($at) {
            $s->forceFill(['created_at' => $at])->save();
        }

        return $s;
    }

    public function test_nothing_new_means_no_badge(): void
    {
        $this->contribution()->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertSame(0, $this->badge());
    }

    public function test_every_kind_of_new_transaction_counts(): void
    {
        $this->contribution();                                          // contribution / don confirmé
        PaymentTransaction::create([                                    // paiement de signature à distance
            'userId' => $this->me->id, 'churchId' => $this->church->id, 'paymentMethod' => 'card',
            'paymentGateway' => 'flutterwave', 'amountPerMonth' => 1000, 'monthsCount' => 1, 'totalAmount' => 1000,
            'fees' => 53, 'amountCharged' => 1053, 'months' => json_encode(['2026-10']),
            'payment_reference' => 'SIG-' . Str::random(6), 'status' => 'completed', 'signatureType' => 'distance',
        ]);
        $this->especes('2026-09');                                      // signature en espèces chez l'ancien

        $this->assertSame(3, $this->badge());
    }

    public function test_pending_and_failed_payments_never_count(): void
    {
        $this->contribution('pending');
        $this->contribution('failed');

        $this->assertSame(0, $this->badge());
    }

    public function test_cash_signatures_signed_together_are_one_transaction(): void
    {
        $this->especes('2026-08');
        $this->especes('2026-09');
        $this->especes('2026-10');

        $this->assertSame(1, $this->badge()); // un passage chez l'ancien, trois mois
    }

    public function test_a_payment_counts_when_it_is_confirmed_not_when_it_was_started(): void
    {
        $this->me->forceFill(['last_read_transactions_at' => now()->subMinutes(10)])->save();
        // Lancé il y a 30 min (avant la dernière consultation), confirmé à l'instant.
        $c = $this->contribution();
        $c->forceFill(['created_at' => now()->subMinutes(30), 'confirmed_at' => now()])->save();

        $this->assertSame(1, $this->badge());

        // Lancé ET confirmé avant la dernière consultation : déjà vu.
        $c->forceFill(['confirmed_at' => now()->subMinutes(20)])->save();
        $this->assertSame(0, $this->badge());
    }

    public function test_other_peoples_transactions_never_count(): void
    {
        $autre = User::factory()->create(['role' => 'paroissien', 'churchId' => $this->church->id, 'actif' => true]);
        $this->contribution(user: $autre);

        $this->assertSame(0, $this->badge());
    }

    public function test_opening_my_transactions_resets_the_badge(): void
    {
        $this->contribution();
        $this->assertSame(1, $this->badge());

        $this->postJson('/api/v1/transactions/lues')->assertOk();

        $this->assertSame(0, $this->badge());
        $this->assertNotNull($this->me->fresh()->last_read_transactions_at);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        $this->contribution(); // une nouvelle après
        $this->assertSame(1, $this->badge());
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_brand_new_account_counts_from_its_creation(): void
    {
        $neuf = User::factory()->create(['role' => 'paroissien', 'churchId' => $this->church->id, 'actif' => true]);
        $neuf->forceFill(['last_read_transactions_at' => null, 'created_at' => now()->subHour()])->save();
        Sanctum::actingAs($neuf);
        $this->contribution(user: $neuf);

        $this->assertSame(1, $this->badge());
    }
}
