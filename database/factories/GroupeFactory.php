<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\Groupe;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GroupeFactory extends Factory
{
    protected $model = Groupe::class;

    public function definition(): array
    {
        return [
            'uuid'     => (string) Str::uuid(),
            'churchId' => Church::factory(),
            'nom'      => fake()->unique()->word(),
        ];
    }
}
