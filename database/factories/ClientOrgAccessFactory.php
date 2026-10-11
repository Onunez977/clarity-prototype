<?php

namespace Database\Factories;

use App\Models\ClientOrgAccess;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientOrgAccess>
 */
class ClientOrgAccessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_org_id' => Org::factory()->client(),
            'repo_org_id' => Org::factory(),
            'granted_by_user_id' => null,
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked_at' => now(),
        ]);
    }
}
