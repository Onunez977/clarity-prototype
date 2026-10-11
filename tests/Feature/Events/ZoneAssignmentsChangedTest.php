<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\Scout\ZoneAssignmentsChanged;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\ZoneAssignmentSyncer;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ZoneAssignmentsChangedTest extends TestCase
{
    private Org $org;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Org::factory()->create();
        $this->zone = Zone::create([
            'org_id' => $this->org->id,
            'name' => 'Test Zone',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_replace_mode_dispatches_event_for_added_and_removed_users(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $userA = $this->makeKitUser();
        $userB = $this->makeKitUser();

        $this->zone->users()->sync([$userA->id]);

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($this->zone, [$userB->id], ZoneAssignmentSyncer::MODE_REPLACE);

        sort($affected);
        $expected = [$userA->id, $userB->id];
        sort($expected);
        $this->assertEquals($expected, $affected);

        Event::assertDispatched(
            ZoneAssignmentsChanged::class,
            fn (ZoneAssignmentsChanged $event) => $event->userId === $userA->id
        );
        Event::assertDispatched(
            ZoneAssignmentsChanged::class,
            fn (ZoneAssignmentsChanged $event) => $event->userId === $userB->id
        );
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_add_mode_only_dispatches_for_new_additions(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $existing = $this->makeKitUser();
        $newcomer = $this->makeKitUser();

        $this->zone->users()->sync([$existing->id]);

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($this->zone, [$existing->id, $newcomer->id], ZoneAssignmentSyncer::MODE_ADD);

        $this->assertEquals([$newcomer->id], $affected);

        Event::assertDispatchedTimes(ZoneAssignmentsChanged::class, 1);
        Event::assertDispatched(
            ZoneAssignmentsChanged::class,
            fn (ZoneAssignmentsChanged $event) => $event->userId === $newcomer->id
        );
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_no_change_dispatches_nothing(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $user = $this->makeKitUser();
        $this->zone->users()->sync([$user->id]);

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($this->zone, [$user->id], ZoneAssignmentSyncer::MODE_REPLACE);

        $this->assertSame([], $affected);
        Event::assertNotDispatched(ZoneAssignmentsChanged::class);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_event_broadcasts_on_private_user_channel(): void
    {
        $event = new ZoneAssignmentsChanged(42);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-clarity.scout.user.42', $channels[0]->name);

        $this->assertSame('zones.changed', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(42, $payload['user_id']);
        $this->assertArrayHasKey('changed_at', $payload);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_implements_should_rescue_so_broadcast_failures_do_not_roll_back_the_save(): void
    {
        $this->assertInstanceOf(ShouldRescue::class, new ZoneAssignmentsChanged(1));
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_syncer_normalizes_string_user_ids_from_filament_selects(): void
    {
        // Filament Select::make multi-selects emit values as strings; syncer
        // must coerce them so the diff against integer DB IDs is correct and
        // we don't broadcast spurious "removed" events for users still on the
        // zone.
        Event::fake([ZoneAssignmentsChanged::class]);

        $user = $this->makeKitUser();
        $this->zone->users()->sync([$user->id]);

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($this->zone, [(string) $user->id], ZoneAssignmentSyncer::MODE_REPLACE);

        $this->assertSame([], $affected);
        Event::assertNotDispatched(ZoneAssignmentsChanged::class);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_syncer_deduplicates_repeated_user_ids_in_input(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $user = $this->makeKitUser();

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers(
                $this->zone,
                [$user->id, $user->id, (string) $user->id],
                ZoneAssignmentSyncer::MODE_REPLACE
            );

        $this->assertSame([$user->id], $affected);

        Event::assertDispatchedTimes(ZoneAssignmentsChanged::class, 1);

        // Single pivot row, not three.
        $this->assertSame(
            1,
            $this->zone->fresh()->users()->count(),
        );
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_syncer_add_mode_is_idempotent_for_users_already_assigned(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $existing = $this->makeKitUser();
        $this->zone->users()->sync([$existing->id]);

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($this->zone, [$existing->id], ZoneAssignmentSyncer::MODE_ADD);

        $this->assertSame([], $affected);
        Event::assertNotDispatched(ZoneAssignmentsChanged::class);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_syncer_handles_bulk_replace_without_duplicate_events_per_user(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $originalA = $this->makeKitUser();
        $originalB = $this->makeKitUser();
        $newcomerA = $this->makeKitUser();
        $newcomerB = $this->makeKitUser();

        $this->zone->users()->sync([$originalA->id, $originalB->id]);

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers(
                $this->zone,
                [$newcomerA->id, $newcomerB->id],
                ZoneAssignmentSyncer::MODE_REPLACE
            );

        // 2 removed + 2 added; each user appears exactly once in affected list.
        $this->assertCount(4, $affected);
        $this->assertCount(4, array_unique($affected));

        // Exactly one event per affected user.
        Event::assertDispatchedTimes(ZoneAssignmentsChanged::class, 4);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_replace_with_empty_users_array_clears_zone_and_broadcasts_for_each_removed(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $userA = $this->makeKitUser();
        $userB = $this->makeKitUser();
        $this->zone->users()->sync([$userA->id, $userB->id]);

        $affected = app(ZoneAssignmentSyncer::class)
            ->syncZoneUsers($this->zone, [], ZoneAssignmentSyncer::MODE_REPLACE);

        sort($affected);
        $expected = [$userA->id, $userB->id];
        sort($expected);
        $this->assertSame($expected, $affected);

        $this->assertSame(0, $this->zone->fresh()->users()->count());
        Event::assertDispatchedTimes(ZoneAssignmentsChanged::class, 2);
    }

    private function makeKitUser(): User
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        return User::factory()->forOrg($this->org)->forKit($kit)->create();
    }
}
