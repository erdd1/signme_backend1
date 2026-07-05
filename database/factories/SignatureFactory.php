<?php

namespace Database\Factories;

use App\Models\Signature;
use App\Models\User;
use App\Models\Church;
use Illuminate\Database\Eloquent\Factories\Factory;

class SignatureFactory extends Factory
{
    protected $model = Signature::class;

    public function definition(): array
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'churchId' => $church->id,
        ]);

        return [
            'userId'         => $user->id,
            'nomParoissien'  => $user->nom,
            'churchId'       => $church->id,

            'monthIndex'     => now()->month - 1,
            'month'          => now()->format('Y-m'),
            'year'           => now()->year,

            'amount'         => 1000,
            'fees'           => 0,

            'paymentMethod'  => 'ESPECES',
            'phoneNumber'    => null,

            'signatureType'  => 'presentiel',
            'status'         => 'completed',

            'ancienId'       => null,
            'ancienNom'      => null,

            'payment_reference' => null,
        ];
    }

    public function distance(): static
    {
        return $this->state(fn () => [
            'signatureType' => 'distance',
            'paymentMethod' => 'ORANGE MONEY',
            'phoneNumber'   => '699123456',
            'fees'          => 100,
        ]);
    }

    public function presentiel(): static
    {
        return $this->state(fn () => [
            'signatureType' => 'presentiel',
            'paymentMethod' => 'ESPECES',
            'phoneNumber'   => null,
            'fees'          => 0,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
        ]);
    }
}