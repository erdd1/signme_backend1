<?php

namespace Tests\Unit\Evenement;

use App\Core\Exceptions\ApiException;
use App\Modules\Evenement\Application\Services\ContributionFeeCalculatorService;
use Tests\TestCase;

class ContributionFeeCalculatorServiceTest extends TestCase
{
    /**
     * @dataProvider feeGridProvider
     */
    public function test_calculate_returns_expected_fee(int $montant, int $fraisAttendus): void
    {
        $this->assertSame($fraisAttendus, ContributionFeeCalculatorService::calculate($montant));
    }

    public static function feeGridProvider(): array
    {
        return [
            // Palier 500-950 -> 50
            'borne basse 500'      => [500, 50],
            'exemple utilisateur 700' => [700, 50],
            'borne haute 950'      => [950, 50],

            // Palier 1000-5000 -> 100
            'borne basse 1000'     => [1000, 100],
            'exemple utilisateur 4000' => [4000, 100],
            'borne haute 5000'     => [5000, 100],

            // Palier 5050-10000 -> 200
            'borne basse 5050'     => [5050, 200],
            'borne haute 10000'    => [10000, 200],

            // Palier 10050-50000 -> 300
            'borne basse 10050'    => [10050, 300],
            'borne haute 50000'    => [50000, 300],

            // Palier 50050-99950 -> 400
            'borne basse 50050'    => [50050, 400],
            'borne haute 99950'    => [99950, 400],

            // Palier 100000-999950 -> 400 x premier chiffre
            'borne basse 100000'   => [100000, 400], // premier chiffre 1
            'exemple utilisateur 560000' => [560000, 2000], // premier chiffre 5
            'premier chiffre 9 950000'   => [950000, 3600],
            'borne haute 999950'   => [999950, 3600],

            // Montant maximum
            'montant maximum 1000000' => [1000000, 10000],
        ];
    }

    /**
     * @dataProvider calculateTotalProvider
     */
    public function test_calculate_total(int $montant, int $frais, int $total): void
    {
        $result = ContributionFeeCalculatorService::calculateTotal($montant);

        $this->assertSame($montant, $result['montant']);
        $this->assertSame($frais, $result['frais']);
        $this->assertSame($total, $result['total']);
    }

    public static function calculateTotalProvider(): array
    {
        return [
            'exemple utilisateur 700 -> 750' => [700, 50, 750],
            'exemple 4000 -> 4100'           => [4000, 100, 4100],
        ];
    }

    /**
     * @dataProvider invalidAmountProvider
     */
    public function test_calculate_rejects_amounts_out_of_bounds_or_not_multiple_of_50(int $montant): void
    {
        $this->expectException(ApiException::class);
        ContributionFeeCalculatorService::calculate($montant);
    }

    public static function invalidAmountProvider(): array
    {
        return [
            'sous le minimum'        => [499],
            'au dessus du maximum'   => [1000001],
            'pas multiple de 50'     => [510],
            'zero'                   => [0],
            'negatif'                => [-500],
        ];
    }

    public function test_is_valid_amount(): void
    {
        $this->assertTrue(ContributionFeeCalculatorService::isValidAmount(500));
        $this->assertTrue(ContributionFeeCalculatorService::isValidAmount(1000000));
        $this->assertFalse(ContributionFeeCalculatorService::isValidAmount(499));
        $this->assertFalse(ContributionFeeCalculatorService::isValidAmount(1000001));
        $this->assertFalse(ContributionFeeCalculatorService::isValidAmount(505));
    }
}
