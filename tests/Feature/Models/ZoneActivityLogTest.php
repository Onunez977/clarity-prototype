<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\ZoneAssignmentSyncer;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ZoneActivityLogTest extends TestCase
{
    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Org::factory()->create();
    }

    #[Group('clarity-scout')]
    public function test_creating_and_updating_a_zone_is_logged(): void
    {
        $zone = Zone::create([
            'org_id' => $this->org->id,
            'name' => 'North',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'zone',
            'subject_type' => Zone::class,
            'subject_id' => $zone->id,
            'event' => 'created',
        ]);

        $zone->update(['name' => 'North (renamed)']);

        $updated = Activity::query()
            ->where('log_name', 'zone')
            ->where('subject_id', $zone->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($updated);
        $this->assertSame('North (renamed)', $updated->properties['attributes']['name'] ?? null);
        $this->assertSame('North', $updated->properties['old']['name'] ?? null);
    }

    #[Group('clarity-scout')]
    public function test_deleting_a_zone_is_logged(): void
    {
        $zone = Zone::create([
            'org_id' => $this->org->id,
            'name' => 'South',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);

        $zone->delete();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'zone',
            'subject_id' => $zone->id,
            'event' => 'deleted',
        ]);
    }

    #[Group('clarity-scout')]
    public function test_reassigning_drivers_logs_added_and_removed(): void
    {
        $zone = Zone::create([
            'org_id' => $this->org->id,
            'name' => 'West',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);

        $existing = $this->makeKitUser();
        $newcomer = $this->makeKitUser();
        $zone->users()->sync([$existing->id]);

        app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($zone, [$newcomer->id], ZoneAssignmentSyncer::MODE_REPLACE);

        $activity = Activity::query()
            ->where('log_name', 'zone')
            ->where('subject_id', $zone->id)
            ->where('description', 'Zone drivers reassigned')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame([$newcomer->id], $activity->properties['added'] ?? null);
        $this->assertSame([$existing->id], $activity->properties['removed'] ?? null);
    }

    #[Group('clarity-scout')]
    public function test_noop_reassignment_does_not_log(): void
    {
        $zone = Zone::create([
            'org_id' => $this->org->id,
            'name' => 'East',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);

        $user = $this->makeKitUser();
        $zone->users()->sync([$user->id]);

        app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($zone, [$user->id], ZoneAssignmentSyncer::MODE_REPLACE);

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'zone',
            'subject_id' => $zone->id,
            'description' => 'Zone drivers reassigned',
        ]);
    }

    private function makeKitUser(): User
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        return User::factory()->forOrg($this->org)->forKit($kit)->create();
    }
}
