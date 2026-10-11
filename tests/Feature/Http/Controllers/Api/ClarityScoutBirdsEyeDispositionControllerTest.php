<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\DispositionEnum;
use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Jobs\SyncDispositionToClickhouse;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use App\Models\ScanValidation;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Queue;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Scout Bird's Eye admin disposition endpoint — org-scoping (not kit-scoping)
 * + the reason-normalization regression guard (DEV-2235).
 */
class ClarityScoutBirdsEyeDispositionControllerTest extends TestCase
{
    private Org $org;

    private User $admin;

    private Kit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $this->org = Org::factory()->create();
        $this->makeOrgPro($this->org, FeatureEnum::CLARITY_SCOUT->value);

        $this->admin = User::factory()->forOrg($this->org)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);
        setPermissionsTeamId($this->org->id);
        $this->admin->assignRole(RoleEnum::ADMIN->value);

        $this->kit = Kit::factory()->forOrg($this->org)->create();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_admin_can_disposition_a_scan_on_any_kit_in_their_own_org(): void
    {
        // The kit belongs to no particular driver — proves org-scoping, not
        // kit-scoping, is what governs the admin endpoint.
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->assertNull($scan->disposition);

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::NEW_HIT->value, $scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_scan_belonging_to_a_different_org(): void
    {
        // Kept outside the try/catch, unlike DispositionController: a
        // tenancy failure must 404, not 500.
        $scan = Scan::factory()->create();

        $this->assertNull($scan->disposition);

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertStatus(404);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_nonexistent_scan(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => fake()->numberBetween(999999, 9999999),
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertStatus(404);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_disposition_a_scan_without_a_disposition(): void
    {
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
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
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => fake()->words(3, true),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['disposition']);

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_admin_cannot_set_an_admin_only_disposition_value(): void
    {
        // Pins the decided product call: even though this is the admin
        // endpoint, it reuses ScoutScanDispositionRequest and remains
        // restricted to the 5 driver-facing values.
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        foreach ([DispositionEnum::VALID, DispositionEnum::REVIEWED, DispositionEnum::INVALID] as $adminOnly) {
            $this->actingAs($this->admin)
                ->postJson('/scout/org-map/api/scan/disposition', [
                    'scan_id' => $scan->id,
                    'disposition' => $adminOnly->value,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['disposition']);
        }

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_non_invalid_scan_disposition_with_no_reason_does_not_create_a_scan_validation(): void
    {
        // The most important test in this file: map.js omits `reason`
        // entirely for every non-Invalid-Scan disposition, so a missing key
        // must still normalize to 'other' or Scan::updateDisposition()
        // spuriously creates a human-review ScanValidation row.
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
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
        // Pins FIX B/DEV-2059 (mirrored from the driver controller): the
        // controller must force reason to 'other' for any disposition other
        // than Invalid Scan, even if a client hand-crafts a review reason
        // alongside it — otherwise a spurious human-review ScanValidation
        // row would be created.
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::MOVING->value,
                'reason' => 'not a plate',
            ])
            ->assertSuccessful()
            ->assertStatus(200);

        $this->assertEquals(DispositionEnum::MOVING->value, $scan->fresh()->disposition);
        $this->assertFalse(ScanValidation::where('scan_id', $scan->id)->exists());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_invalid_scan_with_a_real_reason_still_creates_a_scan_validation(): void
    {
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::INVALID_SCAN->value,
                'reason' => 'not a plate',
            ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::INVALID_SCAN->value, $scan->fresh()->disposition);

        $scanValidation = ScanValidation::where('scan_id', $scan->id)->first();
        $this->assertNotNull($scanValidation);
        $this->assertTrue($scanValidation->not_a_plate);
        $this->assertFalse($scanValidation->not_readable);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_activity_is_attributed_to_the_admin_user(): void
    {
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        Activity::truncate();

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertSuccessful();

        $activity = Activity::query()->where('log_name', 'Disposition')->first();

        $this->assertNotNull($activity);
        $this->assertEquals($this->admin->id, $activity->causer_id);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_disposition_dispatches_clickhouse_sync(): void
    {
        Queue::fake();

        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
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
    public function test_disposition_analytics_fires_with_from_scout_and_birds_eye_page(): void
    {
        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $analytics = Mockery::mock(AnalyticsService::class);
        $analytics->shouldReceive('track')
            ->once()
            ->withArgs(fn (string $feature, $user, array $params, ?string $page = null): bool => $feature === 'disposition-saved'
                && ($params['from_scout'] ?? null) === true
                && $page === 'clarity-scout-birds-eye');
        Analytics::swap($analytics);

        $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertSuccessful();
    }

    #[Group('clarity-scout')]
    public function test_non_admin_user_is_forbidden(): void
    {
        $user = User::factory()->forOrg($this->org)->create();
        setPermissionsTeamId($this->org->id);
        $user->assignRole(RoleEnum::USER->value);

        $scan = Scan::factory()->forOrg($this->org)->belongsToKit($this->kit)->create();

        $this->actingAs($user)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertForbidden();

        $this->assertNull($scan->fresh()->disposition);
    }

    #[Group('clarity-scout')]
    public function test_admin_without_the_scout_feature_is_forbidden(): void
    {
        $plainOrg = Org::factory()->create(); // not pro / no Scout grant
        $admin = User::factory()->forOrg($plainOrg)->create();
        setPermissionsTeamId($plainOrg->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $kit = Kit::factory()->forOrg($plainOrg)->create();
        $scan = Scan::factory()->forOrg($plainOrg)->belongsToKit($kit)->create();

        $this->actingAs($admin)
            ->postJson('/scout/org-map/api/scan/disposition', [
                'scan_id' => $scan->id,
                'disposition' => DispositionEnum::NEW_HIT->value,
            ])
            ->assertForbidden();

        $this->assertNull($scan->fresh()->disposition);
    }
}
