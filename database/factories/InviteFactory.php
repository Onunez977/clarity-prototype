<?php

namespace Database\Factories;

use App\Models\Invite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Str::random(),
            'user_id' => User::factory(),
            'invitable_type' => 'user',
            'invitable_id' => User::factory(),
            'meta' => [],
            'accepted_at' => null,
            'expires_at' => null,
        ];
    }

    public function fromUser(User $user): self
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function toUser(User $user): self
    {
        return $this->state([
            'invitable_type' => 'user',
            'invitable_id' => $user->id,
        ]);
    }
}
