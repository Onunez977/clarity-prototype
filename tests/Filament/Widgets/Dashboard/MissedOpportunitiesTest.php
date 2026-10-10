<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Models\MissedOpportunity;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\MissedOpportunities;
use App\Models\Org;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class MissedOpportunitiesTest extends TestCase
{
    use RequiresClickhouse;
    use StatHelper;

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_card_key_and_stat_color(): void
    {
        $widget = new MissedOpportunities;

        $this->assertSame('missed-opportunities', $widget->getCardKey());

        // Nothing else in the suite pins colour -- this is the only guard against a
        // silent 'default => gray' regression, which would render identically to the
        // locked (withheld) state.
        $this->assertSame('primary', $widget->getStatColor());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_the_card_and_dataset_labels_are_both_relabelled(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $widget = new MissedOpportunities;

        $this->assertSame('Missed Opportunities', $widget->getCardLabel());
        $this->assertSame('Missed Opportunities', $widget->getDatasetLabel());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_can_render_stats(): void
    {
        $this->travelTo(now()->setTimezone('America/Chicago')->startOfDay()->addHours(12));

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $total = fake()->numberBetween(1, 100);
        cache()->put($this->cacheKey(
            key: 'today',
            orgId: auth()->user()->org_id,
            class: MissedOpportunities::class,
        ), $total);

        Livewire::test(MissedOpportunities::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Today: '.number_format($total));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(MissedOpportunities::class)
            ->assertForbidden();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_query_counts_distinct_hotlists_scoped_to_the_viewer_org_excludes_nicb_and_other_orgs(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        $org = $user->org;
        $otherOrg = Org::factory()->create();

        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $inWindow = $start->clone()->addDays(2);

        // One hotlist with 3 rows in-window must count once (grain).
        MissedOpportunity::factory()
            ->forOrg($org)
            ->count(3)
            ->create(['hotlist_id' => 111, 'scanned_at' => $inWindow->toDateTimeString()]);

        // A second, distinct hotlist in-window still accumulates.
        MissedOpportunity::factory()
            ->forOrg($org)
            ->create(['hotlist_id' => 222, 'scanned_at' => $inWindow->toDateTimeString()]);

        // Excluded: NICB integration.
        MissedOpportunity::factory()
            ->forOrg($org)
            ->create(['hotlist_id' => 333, 'scanned_at' => $inWindow->toDateTimeString(), 'integration_id' => IntegrationEnum::NICB->value]);

        // Excluded: a different org's row.
        MissedOpportunity::factory()
            ->forOrg($otherOrg)
            ->create(['hotlist_id' => 444, 'scanned_at' => $inWindow->toDateTimeString()]);

        $widget = new MissedOpportunities;
        $method = new \ReflectionMethod($widget, 'getModelQueryForDates');
        $method->setAccessible(true);

        $total = $method->invoke($widget, $start->toDateTimeString(), $end->toDateTimeString());

        $this->assertSame(2, $total);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_query_respects_the_between_window(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        $org = $user->org;

        $inWindowStart = now()->startOfMonth();
        $inWindowEnd = now()->endOfMonth();

        MissedOpportunity::factory()
            ->forOrg($org)
            ->create(['hotlist_id' => 555, 'scanned_at' => $inWindowStart->clone()->addDay()->toDateTimeString()]);

        // Outside the window entirely.
        MissedOpportunity::factory()
            ->forOrg($org)
            ->create(['hotlist_id' => 666, 'scanned_at' => $inWindowStart->clone()->subMonths(2)->toDateTimeString()]);

        $widget = new MissedOpportunities;
        $method = new \ReflectionMethod($widget, 'getModelQueryForDates');
        $method->setAccessible(true);

        $total = $method->invoke($widget, $inWindowStart->toDateTimeString(), $inWindowEnd->toDateTimeString());

        $this->assertSame(1, $total);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_locked_stat_never_computes_the_value_even_with_one_pre_seeded_in_cache(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $orgId = auth()->user()->org_id;

        cache()->put($this->cacheKey(
            key: 'this_month',
            orgId: $orgId,
            class: MissedOpportunities::class,
        ), 999);

        $widget = new MissedOpportunities;
        $stat = $widget->lockedStat();

        $this->assertSame('—', (string) $stat->getValue());
        $this->assertArrayNotHasKey('wire:click', $stat->getExtraAttributes());
    }

    /**
     * AC-2's tripwire. prep-context promised "the four date-filter windows"; the first cut
     * only exercised `today`, so nothing pinned that the others resolve to DIFFERENT cache
     * keys for this card -- the exact defect a buildWidgetCacheKey() collision produces.
     *
     * Two mechanisms, so two assertion styles, and conflating them is why a naive four-way
     * Livewire assertion fails:
     *   - today / last_7d  -> currentPeriodTotal(), flat key, and getDescription() renders
     *     the number, so Livewire text assertions reach it (as RecoveryCountsTest does).
     *   - this_month / last_month -> the getMonthlyData() bucket array, keyed 'this_month'
     *     for the current month but 'Y-m' for every prior one. That total surfaces only
     *     through CompanyMetricsOverview's Stat, never in this widget's own description --
     *     so it is asserted on getCardTotal() directly, which IS the real resolution path.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_can_switch_stats_across_all_four_date_windows(): void
    {
        $this->travelTo(now()->setTimezone('America/Chicago')->startOfDay()->addHours(12));

        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value, pro: true)
        );

        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';

        // Distinctive 6-digit sentinels; distinctness is the point. A shared key between any
        // two windows makes one of the four assertions below read another window's number.
        $today = 811001;
        $last7d = 822002;
        $thisMonth = 833003;
        $lastMonth = 844004;

        cache()->put($this->cacheKey(key: 'today', orgId: $orgId, class: MissedOpportunities::class), $today);
        cache()->put($this->cacheKey(key: 'last_7d', orgId: $orgId, class: MissedOpportunities::class), $last7d);
        cache()->put($this->cacheKey(key: 'this_month', orgId: $orgId, class: MissedOpportunities::class), $thisMonth);
        cache()->put($this->cacheKey(
            key: now(tz: $timezone)->startOfMonth()->subMonth()->format('Y-m'),
            orgId: $orgId,
            class: MissedOpportunities::class,
        ), $lastMonth);

        // today / last_7d: rendered by getDescription().
        Livewire::test(MissedOpportunities::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Today: '.number_format($today))
            ->assertDontSeeText(number_format($last7d))
            ->dispatch('refresh-dashboard', 'last_7d')
            ->assertSeeText('Last 7 Days: '.number_format($last7d))
            ->assertDontSeeText(number_format($today));

        // this_month / last_month: the bucket array, via the real getCardTotal() path.
        $widget = new MissedOpportunities;

        $widget->date = 'this_month';
        $this->assertSame($thisMonth, $widget->getCardTotal(), 'this_month must read its own bucket');

        $widget = new MissedOpportunities;
        $widget->date = 'last_month';
        $this->assertSame($lastMonth, $widget->getCardTotal(), 'last_month must read the prior Y-m bucket, not this_month');
    }
}
