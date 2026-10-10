<?php

namespace Database\Factories;

use App\Models\Shared;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shared>
 */
class SharedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expires_at' => fake()->dateTimeBetween('+1 week', '+1 month'),
        ];
    }
}
