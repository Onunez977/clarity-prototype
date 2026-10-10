<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Models\Kit as ClickhouseKit;
use App\Filament\Widgets\Dashboard\KitUsers;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class KitUsersTest extends TestCase
{
    use RequiresClickhouse;

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        // Pre-seed cache to avoid ClickHouse queries in CI
        cache()->put('kit-status:active-ids:org='.$user->org_id, []);
        cache()->put('kit-status:total:org='.$user->org_id, 0);

        Livewire::test(KitUsers::class)
            ->assertSuccessful()
            ->assertSeeText('Kit Users');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_expanded_kit_users_is_not_lazy(): void
    {
        $this->assertFalse(KitUsers::isLazy());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(KitUsers::class)
            ->assertForbidden()
            ->assertDontSeeText('Kit Users');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_can_render_stat_table(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $kits = ClickhouseKit::factory()
            ->forOrg($user->org)
            ->count(fake()->numberBetween(2, 3))
            ->create();

        cache()->put(
            'kit-status:active-ids:org='.$user->org_id,
            $kits->pluck('id')->toArray()
        );

        Livewire::test(KitUsers::class, ['expanded' => true])
            ->assertSuccessful()
            ->assertDontSeeText('No Kit Users')
            ->assertCanSeeTableRecords($kits);
    }

    /**
     * Repurposed from test_can_filter_by_active_and_inactive (Q6-approved AC change):
     * the Active/Inactive SelectFilter is REMOVED, and because its default was what
     * scoped the table to active kits, the base query itself must now carry that
     * scoping -- proving the expanded list still shows ONLY active kits with no filter
     * present is what protects against the "every kit under 'Active Kit Users'"
     * regression the filter's removal would otherwise cause silently.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_expanded_panel_lists_only_active_kits_and_has_no_filter(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $activeKits = ClickhouseKit::factory()
            ->forOrg($user->org)
            ->count(fake()->numberBetween(2, 3))
            ->create();

        $inactiveKits = ClickhouseKit::factory()
            ->forOrg($user->org)
            ->count(fake()->numberBetween(2, 3))
            ->create();

        cache()->put(
            'kit-status:active-ids:org='.$user->org_id,
            $activeKits->pluck('id')->toArray()
        );

        $component = Livewire::test(KitUsers::class, ['expanded' => true])
            ->assertSuccessful()
            ->assertDontSeeText('No Kit Users')
            ->assertCanSeeTableRecords($activeKits)
            ->assertCanNotSeeTableRecords($inactiveKits);

        $this->assertSame(
            [],
            $component->instance()->getTable()->getFilters(),
            'the Active/Inactive filter must be removed entirely (Q6), not merely hidden'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_can_render_empty_stat_table(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        // Pre-seed cache to avoid ClickHouse queries in CI
        cache()->put('kit-status:active-ids:org='.$user->org_id, []);

        Livewire::test(KitUsers::class, ['expanded' => true])
            ->assertSuccessful()
            ->assertSeeText('No Kit Users');
    }

    /**
     * Pins the collapsed card's two headline numbers (Q5): Camera Kits = total org kit
     * count, Kit Users = the ACTIVE kit-user count -- a STRICT SUBSET of the total, so
     * the two numbers must differ and neither can be silently derived from the other.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_card_shows_active_kit_users_and_an_inactive_kit_description(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $kits = ClickhouseKit::factory()
            ->forOrg($user->org)
            ->count(5)
            ->create();

        $activeKits = $kits->take(2);

        cache()->put(
            'kit-status:active-ids:org='.$user->org_id,
            $activeKits->pluck('id')->toArray()
        );

        Livewire::test(KitUsers::class)
            ->assertSuccessful()
            ->assertSeeText('Active Kit Users');

        $widget = new KitUsers;
        $this->assertSame(5, $widget->cameraKitsCount());
        $this->assertSame(2, $widget->activeKitUsersCount());
        $this->assertNotSame($widget->cameraKitsCount(), $widget->activeKitUsersCount());

        // 5 kits, 2 of them active -> the card's description counts the other 3.
        $this->assertSame(3, $widget->inactiveKitsCount());
        $this->assertSame('3 inactive kits', (string) $widget->toStat()->getDescription());
    }

    /**
     * activeKitUsersCount() counts kit_ids seen SCANNING, which can exceed the org's kit
     * row count when a scan references a kit that no longer has a kit row (deleted kit, or
     * CDC lag). The subtraction must not surface a negative "-2 inactive kits".
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_inactive_kit_count_clamps_when_more_kits_scanned_than_exist(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $kits = ClickhouseKit::factory()
            ->forOrg($user->org)
            ->count(2)
            ->create();

        // Four scanning kit_ids against only two kit rows.
        cache()->put(
            'kit-status:active-ids:org='.$user->org_id,
            [...$kits->pluck('id')->toArray(), 999001, 999002]
        );

        $widget = new KitUsers;

        $this->assertSame(2, $widget->cameraKitsCount());
        $this->assertSame(4, $widget->activeKitUsersCount());
        $this->assertSame(0, $widget->inactiveKitsCount());
        $this->assertSame('0 inactive kits', (string) $widget->toStat()->getDescription());
    }

    /**
     * KitUsers is a native TableWidget in the grid (no compact-card view swap). A
     * re-render must keep filament-widgets::table-widget.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('kits')]
    public function test_kit_users_keeps_the_vendor_table_view_after_a_refresh(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        cache()->put('kit-status:active-ids:org='.$user->org_id, []);
        cache()->put('kit-status:total:org='.$user->org_id, 0);

        $component = Livewire::test(KitUsers::class);

        $this->assertSame(
            'filament-widgets::table-widget',
            (new ReflectionProperty(KitUsers::class, 'view'))->getValue($component->instance())
        );

        $component->call('$refresh');

        $this->assertSame(
            'filament-widgets::table-widget',
            (new ReflectionProperty(KitUsers::class, 'view'))->getValue($component->instance())
        );
    }
}
