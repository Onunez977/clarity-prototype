<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\DispositionEnum;
use App\Facades\Analytics;
use App\Jobs\SyncDispositionToClickhouse;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use App\Models\ScanValidation;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutScanDispositionControllerTest extends TestCase
{
    private User $user;

    private Kit $kit;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Analytics::shouldReceive('track')->zeroOrMoreTimes()->andReturnNull();

        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $this->kit = Kit::factory()->forOrg($org)->create();

        $this->user = User::factory()->forOrg($org)->forKit($this->kit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_disposition_a_scan_in_the_drivers_own_kit(): void
    {
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->assertNull($scan->disposition);

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::NEW_HIT->value, $scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_disposition_dispatches_clickhouse_sync(): void
    {
        Queue::fake();

        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::MOVING->value,
            ])
            ->assertSuccessful();

        Queue::assertPushed(
            SyncDispositionToClickhouse::class,
            fn (SyncDispositionToClickhouse $job): bool => $job->scanId === $scan->id
                && $job->disposition === DispositionEnum::MOVING->value,
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_scan_belonging_to_a_different_kit(): void
    {
        $otherKit = Kit::factory()->forOrg($this->user->org)->create();

        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($otherKit)->create();

        $this->assertNull($scan->disposition);

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertStatus(404);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_scan_belonging_to_a_different_org_entirely(): void
    {
        $scan = Scan::factory()->create();

        $this->assertNull($scan->disposition);

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertStatus(404);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_scan_without_a_disposition(): void
    {
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['disposition']);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_scan_with_an_invalid_disposition(): void
    {
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => fake()->words(3, true),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['disposition']);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_driver_cannot_set_an_admin_only_disposition_value(): void
    {
        // Pins FIX A/DEV-2059: the shared ScanDispositionRequest's `in:` rule
        // accepts all 8 DispositionEnum values, but a Scout driver must only
        // be able to set the 5 driver-facing options. Posting an admin/
        // analytics-only value (e.g. Valid) must 422, not silently succeed —
        // otherwise a driver could skew their own {New Hit, Valid} stats.
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::VALID->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['disposition']);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_driver_can_still_set_an_allowed_disposition_value(): void
    {
        // Contrast to the admin-only rejection above: a genuine driver
        // option must remain unaffected by the Scout-specific restriction.
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::ACCESSIBLE->value,
            ])
            ->assertSuccessful()
            ->assertStatus(200);

        $this->assertEquals(DispositionEnum::ACCESSIBLE->value, $scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_disposition_a_scan_when_reason_is_explicitly_null(): void
    {
        // Pins DEV-2059: the frontend always sends a `reason` key, defaulting
        // to null for every non-Invalid-Scan disposition. A present-but-null
        // `reason` must not 422 under the `sometimes` rule (present+null still
        // triggers `string`/`in:`); this reproduces that exact payload.
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->assertNull($scan->disposition);

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::ACCESSIBLE->value,
                'reason' => null,
            ])
            ->assertSuccessful()
            ->assertStatus(200);

        $this->assertEquals(DispositionEnum::ACCESSIBLE->value, $scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_non_invalid_scan_disposition_with_no_reason_does_not_create_a_scan_validation(): void
    {
        // Pins F6/DEV-2059: the Scout controller must coalesce a missing/null
        // `reason` to 'other' (matching the web path's app.js default) before
        // calling Scan::updateDisposition(), or every legitimate non-Invalid-Scan
        // disposition (New Hit/Accessible/Moving/Inaccessible) spuriously creates
        // a "needs human review" ScanValidation row.
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::ACCESSIBLE->value,
            ])
            ->assertSuccessful()
            ->assertStatus(200);

        $this->assertEquals(DispositionEnum::ACCESSIBLE->value, $scan->fresh()->disposition);
        $this->assertFalse(ScanValidation::where('scan_id', $scan->id)->exists());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_a_hand_crafted_review_reason_is_ignored_for_a_non_invalid_scan_disposition(): void
    {
        // Pins FIX B/DEV-2059: the controller must force reason to 'other'
        // for any disposition other than Invalid Scan, even if a client
        // hand-crafts a review reason alongside it — otherwise a spurious
        // human-review ScanValidation row would be created.
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::ACCESSIBLE->value,
                'reason' => 'not a plate',
            ])
            ->assertSuccessful()
            ->assertStatus(200);

        $this->assertEquals(DispositionEnum::ACCESSIBLE->value, $scan->fresh()->disposition);
        $this->assertFalse(ScanValidation::where('scan_id', $scan->id)->exists());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_id_as_an_array_is_rejected_with_a_validation_error(): void
    {
        // Pins F7/DEV-2059: scan_id had no type rule, so a non-scalar value
        // flowed into Scan::where('id', ...) and could 500 instead of 422ing.
        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => [1, 2],
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scan_id']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_id_as_a_non_numeric_string_is_rejected_with_a_validation_error(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => 'abc',
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scan_id']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_scan_with_an_invalid_reason(): void
    {
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::INVALID_SCAN->value,
                'reason' => 'garbage',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_invalid_scan_reason_is_captured_as_a_scan_validation(): void
    {
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::INVALID_SCAN->value,
                'reason' => 'not a plate',
            ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::INVALID_SCAN->value, $scan->fresh()->disposition);

        $scanValidation = ScanValidation::where('scan_id', $scan->id)->first();
        $this->assertNotNull($scanValidation);
        $this->assertNull($scanValidation->validated_at);
        $this->assertEquals(4, $scanValidation->source);
        $this->assertEquals('platform', $scanValidation->batch);
        $this->assertTrue($scanValidation->not_a_plate);
        $this->assertFalse($scanValidation->not_readable);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_disposition_analytics_is_flagged_as_from_scout(): void
    {
        // Pins the from_scout discriminator: the Scout path tracks the same
        // 'disposition-saved' event as the web DispositionController, so the two
        // are only distinguishable by this flag when analytics are pulled.
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $analytics = Mockery::mock(AnalyticsService::class);
        $analytics->shouldReceive('track')
            ->once()
            ->withArgs(fn (string $feature, $user, array $params): bool => $feature === 'disposition-saved'
                && ($params['from_scout'] ?? null) === true);
        Analytics::swap($analytics);

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertSuccessful();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_disposition_is_persisted_even_when_analytics_tracking_fails(): void
    {
        $scan = Scan::factory()->forOrg($this->user->org)->belongsToKit($this->kit)->create();

        $failingAnalytics = Mockery::mock(AnalyticsService::class);
        $failingAnalytics->shouldReceive('track')
            ->once()
            ->andThrow(new \RuntimeException('analytics unavailable'));
        Analytics::swap($failingAnalytics);

        $this->withToken($this->token)
            ->postJson('/api/scout/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertSuccessful()
            ->assertStatus(200);

        $this->assertEquals(DispositionEnum::NEW_HIT->value, $scan->fresh()->disposition);
    }
}
