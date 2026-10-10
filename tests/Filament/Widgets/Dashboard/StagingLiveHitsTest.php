<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\StagingLiveHits;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class StagingLiveHitsTest extends TestCase
{
    use StatHelper;

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        Livewire::test(StagingLiveHits::class)
            ->assertSuccessful()
            ->assertSeeText('Staging Live Hits');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(StagingLiveHits::class)
            ->assertForbidden();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_can_render_stats(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        // Distinct sentinels for the total and unique cache keys so a future
        // cross-wiring regression (description reading the wrong series) fails
        // loudly instead of silently passing.
        $totalCount = fake()->numberBetween(1, 100);
        $uniqueCount = fake()->numberBetween(101, 200);

        cache()->put($this->cacheKey(
            key: 'today',
            orgId: $user->org_id,
            class: StagingLiveHits::class,
        ), $totalCount);

        cache()->put($this->cacheKey(
            key: 'unique:today',
            orgId: $user->org_id,
            class: StagingLiveHits::class,
        ), $uniqueCount);

        Livewire::test(StagingLiveHits::class, ['date' => 'today', 'expanded' => true])
            ->assertSuccessful()
            ->dispatch('refresh-dashboard', 'today')
            ->assertSeeText('Today: '.number_format($uniqueCount));

        // The compact Company Metrics card (toStat(), not the standalone chart
        // above) surfaces the total as a secondary line -- assert it with the
        // OTHER sentinel so a future cross-wiring regression fails loudly.
        $this->seedMonthlyCacheBuckets($user->org_id, $user->timezone ?? 'UTC');

        $widget = new StagingLiveHits;
        $widget->date = 'today';
        $this->seedRangeRollup($widget, $user->org_id, $uniqueCount);

        $this->assertSame(
            number_format($totalCount).' total hits',
            $widget->toStat()->getDescription()
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_last_month_headline_is_the_last_month_window_not_this_month(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');

        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $orgId = $user->org_id;
        $timezone = $user->timezone ?? 'UTC';

        $this->seedMonthlyCacheBuckets($orgId, $timezone);

        cache()->put($this->cacheKey(
            key: 'unique:this_month',
            orgId: $orgId,
            class: StagingLiveHits::class,
        ), 99);
        cache()->put($this->cacheKey(
            key: 'this_month',
            orgId: $orgId,
            class: StagingLiveHits::class,
        ), 14);
        cache()->put($this->cacheKey(
            key: 'unique:last_month',
            orgId: $orgId,
            class: StagingLiveHits::class,
        ), 0);
        cache()->put($this->cacheKey(
            key: 'last_month',
            orgId: $orgId,
            class: StagingLiveHits::class,
        ), 0);

        $thisMonth = new StagingLiveHits;
        $thisMonth->date = 'this_month';
        $this->seedRangeRollup($thisMonth, $orgId, 14);
        $this->assertSame(14, $thisMonth->getCardTotal());

        $lastMonth = new StagingLiveHits;
        $lastMonth->date = 'last_month';
        $this->seedRangeRollup($lastMonth, $orgId, 0);
        $this->assertSame(0, $lastMonth->getCardTotal(), 'Last Month must not reuse the This Month unique total');
        $this->assertSame('Last Month: 0', strip_tags((string) $lastMonth->getDescription()));
    }

    private function seedRangeRollup(StagingLiveHits $widget, int $orgId, int $total): void
    {
        [$start, $end, $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);

        cache()->put(
            StagingLiveHitLeaderQuery::sharedRangeCacheKey($orgId, $start, $end, $timezone),
            ['totals' => [0 => $total], 'names' => []],
            now()->addMinutes(10),
        );
    }

    private function seedMonthlyCacheBuckets(int $orgId, string $timezone): void
    {
        // resolvePastMonths()/resolvePastUniqueMonths() gate on ALL FOUR hit-widget
        // prefixes being cached for a given month before trusting the cache -- an
        // incompletely-seeded month is treated as uncached and triggers a live batch
        // query that overwrites every prefix.
        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');

            foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
                cache()->put("{$prefix}:{$dateType}:org={$orgId}", fake()->numberBetween(1, 50));
                cache()->put("{$prefix}:unique:{$dateType}:org={$orgId}", fake()->numberBetween(1, 50));
            }
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_invalid_viewer_timezone_renders_utc_counts(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'NotAReal/Zone']],
            role: RoleEnum::ADMIN->value,
        ));

        $this->assertSame('NotAReal/Zone', $user->timezone);

        $orgId = $user->org_id;
        $widget = new StagingLiveHits;
        $months = (new ReflectionMethod($widget, 'collectUncachedMonths'))->invoke($widget, $orgId, 'NotAReal/Zone');
        $expected = [];

        for ($i = 12; $i >= 1; $i--) {
            $expected[now(tz: 'UTC')->startOfMonth()->subMonths($i)->format('Y-m')] = $i;
        }

        $this->assertSame($expected, $months);

        $this->seedMonthlyCacheBuckets($orgId, 'UTC');
        cache()->put('staging-live-hits:this_month:org='.$orgId, 17);
        cache()->put('staging-live-hits:today:org='.$orgId, 11);

        $monthStart = (new ReflectionMethod($widget, 'monthKeyStart'))->invoke($widget, '2026-06', 'NotAReal/Zone');
        $this->assertSame('2026-06-01', $monthStart->toDateString());
        $this->assertSame('UTC', $monthStart->timezone->getName());

        $widget->date = 'this_month';
        $this->seedRangeRollup($widget, $orgId, 8);

        $stat = $widget->toStat();
        $this->assertSame('8', $stat->getValue());
        $this->assertSame('17 total hits', $stat->getDescription());
        $this->assertNotSame('--', $stat->getValue());

        $today = new StagingLiveHits;
        $today->date = 'today';
        [$start, $end, $timezone] = (new ReflectionMethod($today, 'resolveRangeAndTimezone'))->invoke($today);

        $this->assertSame('UTC', $timezone);
        $this->assertSame(now(tz: 'UTC')->startOfDay()->toDateTimeString(), $start);
        $this->assertSame(now(tz: 'UTC')->endOfDay()->toDateTimeString(), $end);

        $this->seedRangeRollup($today, $orgId, 5);
        $todayStat = $today->toStat();

        $this->assertSame('5', $todayStat->getValue());
        $this->assertSame('11 total hits', $todayStat->getDescription());

        Livewire::test(StagingLiveHits::class)
            ->assertSuccessful()
            ->assertDontSeeText('Unavailable');

        Carbon::setTestNow();
    }
}
