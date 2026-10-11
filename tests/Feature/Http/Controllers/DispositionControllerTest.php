<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DispositionEnum;
use App\Facades\Analytics;
use App\Jobs\SyncDispositionToClickhouse;
use App\Models\Scan;
use App\Models\ScanValidation;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\HasSuperUser;
use Tests\TestCase;

class DispositionControllerTest extends TestCase
{
    use HasSuperUser;

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_can_disposition_scan(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::NEW_HIT->value,
            'scan_id' => $scan->id,
        ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::NEW_HIT->value, $scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_disposition_dispatches_clickhouse_sync(): void
    {
        Queue::fake();

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::INVALID->value,
            'scan_id' => $scan->id,
        ])
            ->assertSuccessful();

        Queue::assertPushed(
            SyncDispositionToClickhouse::class,
            fn (SyncDispositionToClickhouse $job): bool => $job->scanId === $scan->id
                && $job->disposition === DispositionEnum::INVALID->value,
        );
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_update_disposition_dispatches_clickhouse_sync_for_every_caller(): void
    {
        Queue::fake();

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $scan->updateDisposition(DispositionEnum::INVALID->value, $this->user, null);

        Queue::assertPushed(
            SyncDispositionToClickhouse::class,
            fn (SyncDispositionToClickhouse $job): bool => $job->scanId === $scan->id
                && $job->disposition === DispositionEnum::INVALID->value,
        );
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_disposition_analytics_is_flagged_as_not_from_scout(): void
    {
        // Complements the Scout controller's from_scout=true: the web path
        // tags the same 'disposition-saved' event as from_scout=false so the
        // two sources stay cleanly filterable when analytics are pulled.
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $analytics = Mockery::mock(AnalyticsService::class);
        $analytics->shouldReceive('track')
            ->once()
            ->withArgs(fn (string $feature, $user, array $params): bool => $feature === 'disposition-saved'
                && ($params['from_scout'] ?? null) === false);
        Analytics::swap($analytics);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::NEW_HIT->value,
            'scan_id' => $scan->id,
        ])
            ->assertSuccessful();
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_cannot_disposition_scan_from_other_org(): void
    {
        $scan = Scan::factory()->create();

        $this->assertNull($scan->disposition);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::NEW_HIT->value,
            'scan_id' => $scan->id,
        ])
            ->assertStatus(500);

        $this->assertNotEquals(DispositionEnum::NEW_HIT->value, $scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_cannot_disposition_non_existent_scan(): void
    {
        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::NEW_HIT->value,
            'scan_id' => fake()->numberBetween(9999, 99999),
        ])
            ->assertStatus(500);
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_cannot_disposition_scan_without_a_disposition(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        $this->post(route('scan.disposition'), [
            'scan_id' => $scan->id,
        ])
            ->assertRedirect();
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_cannot_disposition_scan_with_invalid_disposition(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        $invalid = fake()->words(3, true);

        $this->post(route('scan.disposition'), [
            'disposition' => $invalid,
            'scan_id' => $scan->id,
        ])
            ->assertRedirect();

        $this->assertNotEquals($invalid, $scan->fresh()->disposition);
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_saves_to_scan_validations_on_disposition_reason(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::INACCESSIBLE->value,
            'scan_id' => $scan->id,
            'reason' => 'not a plate',
        ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::INACCESSIBLE->value, $scan->fresh()->disposition);

        $scanValidation = ScanValidation::where('scan_id', $scan->id)->first();
        $this->assertNotNull($scanValidation);

        $this->assertNull($scanValidation->validated_at);
        $this->assertEquals(4, $scanValidation->source);
        $this->assertEquals('platform', $scanValidation->batch);
        $this->assertTrue($scanValidation->not_a_plate);
        $this->assertFalse($scanValidation->not_readable);
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_updates_scan_validations_on_disposition_reason(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::INACCESSIBLE->value,
            'scan_id' => $scan->id,
            'reason' => 'not a plate',
        ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::INACCESSIBLE->value, $scan->fresh()->disposition);

        $scanValidation = ScanValidation::where('scan_id', $scan->id)->first();
        $this->assertNotNull($scanValidation);

        $this->assertNull($scanValidation->validated_at);
        $this->assertTrue($scanValidation->not_a_plate);
        $this->assertFalse($scanValidation->not_readable);

        // Update reason.
        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::INACCESSIBLE->value,
            'scan_id' => $scan->id,
            'reason' => 'plate too blurry',
        ])
            ->assertSuccessful();

        $scanValidation->refresh();

        $this->assertDatabaseCount(ScanValidation::class, 1);

        $this->assertNull($scanValidation->validated_at);
        $this->assertFalse($scanValidation->not_a_plate);
        $this->assertTrue($scanValidation->not_readable);
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_doesnt_update_scan_validations_that_is_already_validated(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::INACCESSIBLE->value,
            'scan_id' => $scan->id,
            'reason' => 'not a plate',
        ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::INACCESSIBLE->value, $scan->fresh()->disposition);

        $scanValidation = ScanValidation::where('scan_id', $scan->id)->first();
        $this->assertNotNull($scanValidation);

        $this->assertNull($scanValidation->validated_at);
        $this->assertTrue($scanValidation->not_a_plate);
        $this->assertFalse($scanValidation->not_readable);

        $scanValidation->update([
            'validated_at' => now(),
        ]);

        // Update reason.
        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::INACCESSIBLE->value,
            'scan_id' => $scan->id,
            'reason' => 'plate too blurry',
        ])
            ->assertSuccessful();

        $scanValidation->refresh();

        $this->assertDatabaseCount(ScanValidation::class, 1);

        $this->assertNotNull($scanValidation->validated_at);
        // Did not change
        $this->assertTrue($scanValidation->not_a_plate);
        $this->assertFalse($scanValidation->not_readable);
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_doesnt_save_to_scan_validations_on_other_disposition_reason(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'scan_id' => $scan->id,
            'reason' => 'other',
        ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::ACCESSIBLE->value, $scan->fresh()->disposition);
        $this->assertNull(ScanValidation::where('scan_id', $scan->id)->first());
    }

    #[Group('controllers')]
    #[Group('disposition')]
    public function test_logs_activity_on_disposition(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $this->assertNull($scan->disposition);

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $this->post(route('scan.disposition'), [
            'disposition' => DispositionEnum::NEW_HIT->value,
            'scan_id' => $scan->id,
            'reason' => 'other',
        ])
            ->assertSuccessful();

        $this->assertEquals(DispositionEnum::NEW_HIT->value, $scan->fresh()->disposition);

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Disposition',
            'description' => 'Scan disposition updated',
        ]);

        $activity = Activity::query()
            ->where('log_name', 'Disposition')
            ->first();

        $this->assertArrayHasKey('scan_id', $activity->properties);
        $this->assertEquals($scan->id, $activity->properties['scan_id']);

        $this->assertArrayHasKey('disposition', $activity->properties);
        $this->assertEquals(DispositionEnum::NEW_HIT->value, $activity->properties['disposition']);

        $this->assertArrayHasKey('reason', $activity->properties);
        $this->assertEquals('other', $activity->properties['reason']);
    }
}
