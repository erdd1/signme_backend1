<?php

namespace Tests\Unit\Retrait;

use App\Modules\Retrait\Application\Services\RetraitFeeCalculatorService;
use Tests\TestCase;

class RetraitFeeCalculatorServiceTest extends TestCase
{
    public function test_frais_nul_par_defaut(): void
    {
        config(['payment.notchpay.transfer_fee_percent' => 0]);

        $result = RetraitFeeCalculatorService::calculate(10000);

        $this->assertSame([
            'montant_demande' => 10000,
            'frais' => 0,
            'montant_net' => 10000,
        ], $result);
    }

    public function test_frais_pourcentage_configure(): void
    {
        config(['payment.notchpay.transfer_fee_percent' => 10]);

        $result = RetraitFeeCalculatorService::calculate(5000);

        $this->assertSame(500, $result['frais']);
        $this->assertSame(4500, $result['montant_net']);
    }

    public function test_frais_arrondi_a_lentier_le_plus_proche(): void
    {
        config(['payment.notchpay.transfer_fee_percent' => 1.5]);

        $result = RetraitFeeCalculatorService::calculate(1000);

        // 1000 * 1.5% = 15 pile
        $this->assertSame(15, $result['frais']);
        $this->assertSame(985, $result['montant_net']);
    }
}
