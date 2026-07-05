<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\Ville;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VilleFactory extends Factory
{
    protected $model = Ville::class;

    public function definition(): array
    {
        return [
            'uuid'        => (string) Str::uuid(),
            'churchId'    => Church::factory(),
            'nom'         => fake()->unique()->city(),
            'estDiaspora' => false,
        ];
    }
}
