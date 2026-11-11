<?php

namespace Tests\Unit\Payment;

use App\Core\Exceptions\ApiException;
use App\Modules\Evenement\Application\Services\CardFeeCalculatorService;
use PHPUnit\Framework\TestCase;

class CardFeeCalculatorServiceTest extends TestCase
{
    public function test_total_is_grossed_up_so_the_church_receives_the_exact_amount(): void
    {
        $r = CardFeeCalculatorService::calculateTotal(1000, 4.8);

        $this->assertSame(1051, $r['total']);          // 1000 / 0.952 = 1050,42 → 1051
        $this->assertSame(51, $r['frais']);
        $this->assertSame(1000, $r['montant']);
        // Après retenue du fournisseur (4,8 % du total), il reste bien au moins 1000.
        $this->assertGreaterThanOrEqual(1000, $r['total'] * (1 - 0.048));
    }

    public function test_an_exact_result_is_not_rounded_up_by_floating_point_noise(): void
    {
        $this->assertSame(1000, CardFeeCalculatorService::calculateTotal(950, 5.0)['total']);
    }

    public function test_zero_percent_means_no_fees(): void
    {
        $r = CardFeeCalculatorService::calculateTotal(5000, 0.0);

        $this->assertSame(['montant' => 5000, 'frais' => 0, 'total' => 5000], $r);
    }

    public function test_amount_bounds_and_step_are_the_same_as_other_contributions(): void
    {
        foreach ([499, 505, 1_000_050, 0, -500] as $bad) {
            try {
                CardFeeCalculatorService::calculateTotal($bad, 4.8);
                $this->fail("Le montant {$bad} aurait dû être refusé.");
            } catch (ApiException $e) {
                $this->assertSame(400, $e->getStatusCode());
            }
        }

        $this->assertSame(500, CardFeeCalculatorService::calculateTotal(500, 0.0)['total']);
        $this->assertSame(1_000_000, CardFeeCalculatorService::calculateTotal(1_000_000, 0.0)['total']);
    }

    public function test_an_absurd_percent_is_rejected(): void
    {
        $this->expectException(ApiException::class);

        CardFeeCalculatorService::calculateTotal(1000, 100.0);
    }
}
