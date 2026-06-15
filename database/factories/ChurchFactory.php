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
            'nom'       => fake()->company(),
            'ville'     => fake()->city(),
            'region'    => fake()->state(),
            'adresse'   => fake()->address(),
            'telephone' => fake()->phoneNumber(),
            'email'     => fake()->safeEmail(),
        ];
    }
}
