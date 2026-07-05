<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\Quartier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuartierFactory extends Factory
{
    protected $model = Quartier::class;

    public function definition(): array
    {
        return [
            'uuid'     => (string) Str::uuid(),
            'churchId' => Church::factory(),
            'nom'      => fake()->unique()->streetName(),
        ];
    }
}
