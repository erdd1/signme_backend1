<?php

namespace Database\Factories;

use App\Models\Church;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChurchFactory extends Factory
{
    protected $model = Church::class;

    public function definition(): array
    {
        return [
            'nom'           => fake()->name(),
            'email'         => fake()->email(),
            'telephone'     => fake()->phoneNumber(),
            'adresse'       => fake()->address(),
            'ville'         => fake()->city(),
            'codePostal'    => fake()->postcode(),
            'pays'          => fake()->country(),
            'logoUrl'       => fake()->url(),
            'actif'         => true,
        ];
    }
}
