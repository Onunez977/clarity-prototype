<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enums\ScoutAnalyticsEvent;
use App\Events\Scout\ZoneAssignmentsChanged;
use App\Events\Scout\ZoneUpdated;
use App\Facades\Analytics;
use App\Filament\Resources\ZoneResource\Pages\ListZones;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\Event;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\ResourceTestCase;

/**
 * The Zones list must fire the SAME real-time broadcasts the map editor fires —
 * quick edit / delete / restore route through ZoneBroadcaster, and reassign
 * (single + bulk) through ZoneAssignmentSyncer — or driver apps fall out of
 * sync. This pins that parity (DEV-2104 primary risk).
 */
#[Group('events')]
#[Group('clarity-scout')]
class ZoneListBroadcastsTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    private function makeKitUser(?Org $org = null): User
    {
        $org ??= $this->user->org;
        $kit = Kit::factory()->forOrg($org)->create();

        return User::factory()->forOrg($org)->forKit($kit)->create();
    }

    // ---- Quick edit (AC6) --------------------------------------------------

    public function test_quick_edit_broadcasts_to_each_assigned_driver_and_leaves_geometry_untouched(): void
    {
        Event::fake([ZoneUpdated::class, ZoneAssignmentsChanged::class]);

        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zone = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverA->id, $driverB->id])->create();
        $originalDefinition = $zone->definition;

        Livewire::test(ListZones::class)
            ->callTableAction('quickEdit', $zone, data: [
                'name' => 'Renamed Zone',
                'description' => 'Updated copy',
                'is_no_scan_zone' => false,
                'color' => '#16a34a',
            ]);

        $zone->refresh();

        $this->assertSame('Renamed Zone', $zone->name);
        $this->assertSame('#16a34a', $zone->color);
        $this->assertSame($originalDefinition, $zone->definition);
        $this->assertEqualsCanonicalizing(
            [$driverA->id, $driverB->id],
            $zone->users()->pluck('users.id')->all(),
        );

        Event::assertDispatchedTimes(ZoneUpdated::class, 2);
        Event::assertNotDispatched(ZoneAssignmentsChanged::class);
        Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $e) => $e->userId === $driverA->id && $e->zoneId === $zone->id);
        Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $e) => $e->userId === $driverB->id && $e->zoneId === $zone->id);
    }

    public function test_quick_edit_no_scan_toggle_forces_gray_even_though_the_color_field_is_disabled(): void
    {
        $zone = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->callTableAction('quickEdit', $zone, data: [
                'name' => 'Off Limits',
                'is_no_scan_zone' => true,
                'color' => '#ea580c',
            ]);

        $zone->refresh();

        $this->assertTrue($zone->is_no_scan_zone);
        $this->assertSame(Zone::NO_SCAN_COLOR, $zone->color);
    }

    public function test_quick_edit_with_no_drivers_broadcasts_nothing(): void
    {
        Event::fake([ZoneUpdated::class]);

        $zone = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->callTableAction('quickEdit', $zone, data: [
                'name' => 'Quiet Zone',
                'is_no_scan_zone' => false,
                'color' => Zone::DEFAULT_COLOR,
            ]);

        Event::assertNotDispatched(ZoneUpdated::class);
    }

    // ---- Delete (AC7) ------------------------------------------------------

    public function test_delete_soft_deletes_and_broadcasts_to_each_previously_assigned_driver(): void
    {
        Event::fake([ZoneUpdated::class]);

        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zone = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverA->id, $driverB->id])->create();

        Livewire::test(ListZones::class)
            ->callTableAction('delete', $zone);

        $this->assertSoftDeleted($zone);

        Event::assertDispatchedTimes(ZoneUpdated::class, 2);
        Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $e) => $e->userId === $driverA->id && $e->zoneId === $zone->id);
        Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $e) => $e->userId === $driverB->id && $e->zoneId === $zone->id);
    }

    // ---- Restore (AC7) -----------------------------------------------------

    public function test_restore_reinstates_the_zone_and_re_broadcasts_to_surviving_drivers(): void
    {
        Event::fake([ZoneUpdated::class]);

        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zone = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverA->id, $driverB->id])->trashed()->create();

        // Pivot rows survive a soft delete, so the prior roster is intact.
        $this->assertEqualsCanonicalizing(
            [$driverA->id, $driverB->id],
            $zone->users()->pluck('users.id')->all(),
        );

        Livewire::test(ListZones::class)
            ->filterTable('trashed', 1)
            ->callTableAction('restore', $zone);

        $this->assertFalse($zone->fresh()->trashed());

        Event::assertDispatchedTimes(ZoneUpdated::class, 2);
        Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $e) => $e->userId === $driverA->id && $e->zoneId === $zone->id);
        Event::assertDispatched(ZoneUpdated::class, fn (ZoneUpdated $e) => $e->userId === $driverB->id && $e->zoneId === $zone->id);
    }

    // ---- Quick reassign parity (AC5) ---------------------------------------

    public function test_quick_reassign_fires_assignment_events_and_no_zone_updated(): void
    {
        Event::fake([ZoneAssignmentsChanged::class, ZoneUpdated::class]);

        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zone = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverA->id])->create();

        Livewire::test(ListZones::class)
            ->callTableAction('quickReassign', $zone, data: [
                'mode' => 'replace',
                'users' => [$driverB->id],
            ]);

        $this->assertEqualsCanonicalizing([$driverB->id], $zone->fresh()->users()->pluck('users.id')->all());

        // Replace = remove A + add B → two assignment broadcasts, no ZoneUpdated.
        Event::assertDispatchedTimes(ZoneAssignmentsChanged::class, 2);
        Event::assertNotDispatched(ZoneUpdated::class);
    }

    // ---- Bulk reassign / delete (AC8) --------------------------------------

    public function test_bulk_reassign_applies_across_every_selected_zone(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);

        $driver = $this->makeKitUser();

        $zoneA = Zone::factory()->forOrg($this->user->org)->create();
        $zoneB = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->callTableBulkAction('bulkReassign', [$zoneA, $zoneB], data: [
                'mode' => 'replace',
                'users' => [$driver->id],
            ]);

        $this->assertEqualsCanonicalizing([$driver->id], $zoneA->fresh()->users()->pluck('users.id')->all());
        $this->assertEqualsCanonicalizing([$driver->id], $zoneB->fresh()->users()->pluck('users.id')->all());

        // One added driver per zone.
        Event::assertDispatchedTimes(ZoneAssignmentsChanged::class, 2);
    }

    public function test_bulk_delete_soft_deletes_and_broadcasts_per_driver_per_zone(): void
    {
        Event::fake([ZoneUpdated::class]);

        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zoneA = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverA->id, $driverB->id])->create();
        $zoneB = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverA->id])->create();

        Livewire::test(ListZones::class)
            ->callTableBulkAction('delete', [$zoneA, $zoneB]);

        $this->assertSoftDeleted($zoneA);
        $this->assertSoftDeleted($zoneB);

        // zoneA → 2 drivers, zoneB → 1 driver = 3 deletion broadcasts.
        Event::assertDispatchedTimes(ZoneUpdated::class, 3);
    }

    // ---- Analytics: exactly one row per action (DEV-2130, no double-count) ---
    //
    // Each list action previously carried BOTH a data-track attribute (fires on
    // click, even on a cancelled modal) AND an explicit server-side track — two
    // rows per action. data-track was removed; tracking now lives once on the
    // execution path. These pin "exactly one" so the duplicate can't regress.

    public function test_quick_edit_emits_exactly_one_analytics_event(): void
    {
        Event::fake([ZoneUpdated::class]);
        $spy = Analytics::spy();

        $zone = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->callTableAction('quickEdit', $zone, data: [
                'name' => 'Renamed',
                'color' => '#2563eb',
                'is_no_scan_zone' => false,
            ]);

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::ZoneEdit->value)
            ->once();
    }

    public function test_delete_emits_exactly_one_analytics_event(): void
    {
        Event::fake([ZoneUpdated::class]);
        $spy = Analytics::spy();

        $zone = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->callTableAction('delete', $zone);

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::ZoneDelete->value)
            ->once();
    }

    public function test_restore_emits_exactly_one_analytics_event(): void
    {
        Event::fake([ZoneUpdated::class]);
        $spy = Analytics::spy();

        $zone = Zone::factory()->forOrg($this->user->org)->trashed()->create();

        Livewire::test(ListZones::class)
            ->filterTable('trashed', 1)
            ->callTableAction('restore', $zone);

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::ZoneRestore->value)
            ->once();
    }

    public function test_quick_reassign_emits_exactly_one_analytics_event(): void
    {
        Event::fake([ZoneAssignmentsChanged::class, ZoneUpdated::class]);
        $spy = Analytics::spy();

        $driver = $this->makeKitUser();
        $zone = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->callTableAction('quickReassign', $zone, data: [
                'mode' => 'replace',
                'users' => [$driver->id],
            ]);

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::ZoneReassign->value)
            ->once();
    }

    public function test_bulk_reassign_emits_exactly_one_analytics_event(): void
    {
        Event::fake([ZoneAssignmentsChanged::class]);
        $spy = Analytics::spy();

        $driver = $this->makeKitUser();
        $zoneA = Zone::factory()->forOrg($this->user->org)->create();
        $zoneB = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->callTableBulkAction('bulkReassign', [$zoneA, $zoneB], data: [
                'mode' => 'replace',
                'users' => [$driver->id],
            ]);

        // One event for the whole bulk action, not one per zone.
        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::ZoneReassign->value)
            ->once();
    }
}
