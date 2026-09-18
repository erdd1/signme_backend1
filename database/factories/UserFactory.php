<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'paroissien',
            'actif' => true,
            'churchId' => null,
            'est_de_service' => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an administrator. Super admin by default —
     * les tests existants supposent un accès total ; utiliser explicitement
     * ->state(['is_super_admin' => false]) pour tester un mini-admin restreint.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'administrateur',
            'is_super_admin' => true,
        ]);
    }

    /**
     * Indicate that the user is a pastor.
     */
    public function pasteur(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'pasteur',
        ]);
    }

    /**
     * Indicate that the user is an elder.
     */
    public function ancien(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'ancien',
            'est_de_service' => true,
        ]);
    }

    /**
     * Indicate that the user is active.
     */
    public function actif(): static
    {
        return $this->state(fn (array $attributes) => [
            'actif' => true,
        ]);
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactif(): static
    {
        return $this->state(fn (array $attributes) => [
            'actif' => false,
        ]);
    }

    /**
     * Set the user's church ID.
     */
    public function forChurch(int $churchId): static
    {
        return $this->state(fn (array $attributes) => [
            'churchId' => $churchId,
        ]);
    }
}