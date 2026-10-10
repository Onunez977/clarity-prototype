<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Scout;

use App\Models\User;
use App\Support\Scout\ScoutForceTokens;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('clarity-scout')]
class ScoutForceTokensTest extends TestCase
{
    public function test_mint_tracks_tokens_per_driver_bucket(): void
    {
        $driverA = User::factory()->create();
        $driverB = User::factory()->create();

        ScoutForceTokens::mintFor($driverA);
        ScoutForceTokens::mintFor($driverB);

        $tracked = Session::get('clarity_scout_force_token_ids');

        $this->assertIsArray($tracked);
        $this->assertArrayHasKey((string) $driverA->id, $tracked);
        $this->assertArrayHasKey((string) $driverB->id, $tracked);
        $this->assertCount(1, $tracked[(string) $driverA->id]);
        $this->assertCount(1, $tracked[(string) $driverB->id]);
    }

    public function test_prune_removes_dropped_ids_from_the_session_bucket(): void
    {
        $driver = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            ScoutForceTokens::mintFor($driver);
        }

        $tracked = Session::get('clarity_scout_force_token_ids');
        $bucket = $tracked[(string) $driver->id];

        $this->assertCount(3, $bucket);
        $this->assertCount(
            3,
            $driver->fresh()->tokens()->where('name', ScoutForceTokens::NAME)->get()
        );

        foreach ($bucket as $id) {
            $this->assertNotNull(PersonalAccessToken::query()->find($id));
        }
    }

    public function test_targeted_revoke_only_affects_one_driver(): void
    {
        $driverA = User::factory()->create();
        $driverB = User::factory()->create();

        ScoutForceTokens::mintFor($driverA);
        ScoutForceTokens::mintFor($driverB);

        ScoutForceTokens::revokeTracked($driverA);

        $this->assertCount(0, $driverA->fresh()->tokens()->where('name', ScoutForceTokens::NAME)->get());
        $this->assertCount(1, $driverB->fresh()->tokens()->where('name', ScoutForceTokens::NAME)->get());

        $tracked = Session::get('clarity_scout_force_token_ids');
        $this->assertArrayNotHasKey((string) $driverA->id, $tracked ?? []);
        $this->assertArrayHasKey((string) $driverB->id, $tracked);
    }

    public function test_full_revoke_clears_every_tracked_driver(): void
    {
        $driverA = User::factory()->create();
        $driverB = User::factory()->create();

        ScoutForceTokens::mintFor($driverA);
        ScoutForceTokens::mintFor($driverB);

        ScoutForceTokens::revokeTracked();

        $this->assertCount(0, $driverA->fresh()->tokens()->where('name', ScoutForceTokens::NAME)->get());
        $this->assertCount(0, $driverB->fresh()->tokens()->where('name', ScoutForceTokens::NAME)->get());
        $this->assertNull(Session::get('clarity_scout_force_token_ids'));
    }

    public function test_legacy_flat_session_shape_is_revoked_on_exit(): void
    {
        $driver = User::factory()->create();
        $plain = ScoutForceTokens::mintFor($driver);
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);

        // Simulate a pre-deploy flat list still sitting in the session.
        Session::put('clarity_scout_force_token_ids', [$token->id]);

        ScoutForceTokens::revokeTracked();

        $this->assertNull(PersonalAccessToken::query()->find($token->id));
        $this->assertNull(Session::get('clarity_scout_force_token_ids'));
    }
}
