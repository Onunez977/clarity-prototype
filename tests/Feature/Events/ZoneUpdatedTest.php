<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\Scout\ZoneUpdated;
use App\Filament\Pages\ManageZones;
use App\Models\Kit;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Support\Facades\Event;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\ResourceTestCase;

class ZoneUpdatedTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_event_broadcasts_on_private_user_channel(): void
    {
        $event = new ZoneUpdated(42, 7);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-clarity.scout.user.42', $channels[0]->name);

        $this->assertSame('zones.changed', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(42, $payload['user_id']);
        $this->assertSame(7, $payload['zone_id']);
        $this->assertArrayHasKey('changed_at', $payload);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_implements_should_rescue_so_broadcast_failures_do_not_roll_back_the_save(): void
    {
        $this->assertInstanceOf(ShouldRescue::class, new ZoneUpdated(42, 7));
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_save_dispatches_event_for_each_assigned_user(): void
    {
        Event::fake([ZoneUpdated::class]);

        $userA = $this->makeKitUser();
        $userB = $this->makeKitUser();

        $zone = $this->makeZone();
        $zone->users()->sync([$userA->id, $userB->id]);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->set('data.name', 'Renamed Zone')
            ->set('data.color', '#9333ea')
            ->call('save');

        Event::assertDispatchedTimes(ZoneUpdated::class, 2);
        Event::assertDispatched(
            ZoneUpdated::class,
            fn (ZoneUpdated $event) => $event->userId === $userA->id && $event->zoneId === $zone->id
        );
        Event::assertDispatched(
            ZoneUpdated::class,
            fn (ZoneUpdated $event) => $event->userId === $userB->id && $event->zoneId === $zone->id
        );
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_save_dispatches_nothing_when_no_users_assigned(): void
    {
        Event::fake([ZoneUpdated::class]);

        $zone = $this->makeZone();

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->set('data.name', 'Renamed Zone')
            ->call('save');

        Event::assertNotDispatched(ZoneUpdated::class);
    }

    #[Group('events')]
    #[Group('clarity-scout')]
    public function test_delete_dispatches_event_for_each_previously_assigned_user(): void
    {
        Event::fake([ZoneUpdated::class]);

        $userA = $this->makeKitUser();
        $userB = $this->makeKitUser();

        $zone = $this->makeZone();
        $zone->users()->sync([$userA->id, $userB->id]);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->call('delete');

        Event::assertDispatchedTimes(ZoneUpdated::class, 2);
        Event::assertDispatched(
            ZoneUpdated::class,
            fn (ZoneUpdated $event) => $event->userId === $userA->id && $event->zoneId === $zone->id
        );
        Event::assertDispatched(
            ZoneUpdated::class,
            fn (ZoneUpdated $event) => $event->userId === $userB->id && $event->zoneId === $zone->id
        );
    }

    private function makeZone(): Zone
    {
        $zone = Zone::create([
            'org_id' => $this->user->org_id,
            'name' => 'Test Zone',
            'color' => '#2563eb',
            'definition' => [
                'polygons' => [[
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [0, 0], [0, 1], [1, 1], [1, 0], [0, 0],
                    ]],
                ]],
                'zip_codes' => [],
            ],
        ]);

        $zone->rebuildGeometryFromDefinition();

        return $zone;
    }

    private function makeKitUser(): User
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        return User::factory()->forOrg($this->user->org)->forKit($kit)->create();
    }
}
