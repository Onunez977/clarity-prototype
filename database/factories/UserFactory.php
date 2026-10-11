<?php

namespace Database\Factories;

use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'org_id' => Org::factory(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ?? Hash::make('password'),
            'remember_token' => Str::random(10),
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

    public function forOrg(Org $org): self
    {
        return $this->state(['org_id' => $org->id]);
    }

    public function forKit(Kit $kit): self
    {
        return $this->afterCreating(function (User $user) use ($kit) {
            KitSettings::updateOrCreate(
                ['kit_id' => $kit->id],
                ['user_id' => $user->id],
            );
        });
    }
}
