<?php

namespace Tests\Filament\Widgets\Dashboard\Concerns;

use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\HistoricalHits;
use App\Filament\Widgets\Dashboard\LiveHits;
use App\Filament\Widgets\Dashboard\RecoveryLeader;
use App\Filament\Widgets\Dashboard\StagingHistoricalHits;
use App\Filament\Widgets\Dashboard\StagingLiveHits;
use App\Filament\Widgets\Dashboard\UniqueScanCounts;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Pins the DEV-2324 compact-card data helpers added to DashboardWidget: MTD total,
 * %-change vs the same day-range in the prior period, and the sparkline series -- all
 * built on TOP of the existing getMonthlyData()/getGraphLabels() (never modifying their
 * DEV-1927 month arithmetic, which stays covered by DashboardMonthLabelsTest).
 */
class DashboardCardMetricsTest extends TestCase
{
    private ?array $originalClickhouseConfig = null;

    private function actingAsAdmin(): User
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        $this->actingAs($user);

        return $user;
    }

    private function makeWidget(mixed $priorQueryResult = 80): DashboardWidget
    {
        $widget = new class extends DashboardWidget
        {
            public int $queryCalls = 0;

            public mixed $priorQueryResult = 80;

            protected ?string $heading = 'Test Metric';

            public function getRgbColor(): array
            {
                return [10, 20, 30];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                $this->queryCalls++;

                return $this->priorQueryResult;
            }

            public function exposeCacheKey(string $dateType, int $orgId): string
            {
                return $this->buildWidgetCacheKey($dateType, $orgId);
            }
        };

        $widget->priorQueryResult = $priorQueryResult;

        return $widget;
    }

    /**
     * Seeds the 13 getMonthlyData() cache buckets directly (no ClickHouse), following
     * DashboardMonthLabelsTest's pattern. $valuesByMonthsAgo is keyed by "months ago"
     * (12 = the oldest bucket, 0 = 'this_month'); unspecified months default to 0.
     *
     * @param  array<int, int>  $valuesByMonthsAgo
     */
    private function seedMonthlyBuckets(mixed $widget, int $orgId, array $valuesByMonthsAgo): void
    {
        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now()->startOfMonth()->subMonths($i);
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');

            cache()->put($widget->exposeCacheKey($dateType, $orgId), $valuesByMonthsAgo[$i] ?? 0);
        }
    }

    /**
     * Several tests here deliberately point `clarity_clickhouse` at a dead port to force a real
     * Throwable. Restoring is done HERE rather than per-test so it cannot be forgotten: a test
     * that ends with the connection broken would otherwise leave cleanup to Laravel's per-test
     * application refresh, and any future test sharing the process that touches ClickHouse
     * without a guard would fail for a reason unrelated to its subject.
     */
    protected function tearDown(): void
    {
        $this->restoreClickhouseConnection();

        parent::tearDown();
    }

    /**
     * Points the `clarity_clickhouse` read connection at a dead port so a real
     * connect failure surfaces as a genuine Throwable from
     * HitPlateStatsBatchQuery::handle()/::handleRange(), without needing
     * ClickHouse to be down. Same pattern as OrgHotlistCacheTest.
     */
    private function forceClickhouseConnectionToFail(): void
    {
        $this->originalClickhouseConfig ??= Config::get('database.connections.clarity_clickhouse');

        Config::set('database.connections.clarity_clickhouse.driver', 'clickhouse');
        Config::set('database.connections.clarity_clickhouse.host', '127.0.0.1');
        Config::set('database.connections.clarity_clickhouse.port', '1');
        Config::set('database.connections.clarity_clickhouse.https', false);
        Config::set('database.connections.clarity_clickhouse.options', ['protocol' => 'http']);
        Config::set('database.connections.clarity_clickhouse.timeout_connect', 1);
        Config::set('database.connections.clarity_clickhouse.timeout_query', 1);
        Config::set('database.connections.clarity_clickhouse.retries', 0);

        DB::purge('clarity_clickhouse');
    }

    /**
     * Skips a test that genuinely needs a REAL ClickHouse (it forces a connect failure and
     * then restores a working connection, so the pgsql bridge cannot stand in). Mirrors the
     * guard in tests/Concerns/RequiresClickhouse.php, applied per-test because most tests in
     * this class are cache-only and must keep running under the documented bridge fallback.
     */
    /**
     * Two-series charts must use ONE stable colour per dataset. makeDataset() otherwise
     * defaults to generateColors(), a PER-MONTH array that repaints the Total bar with the
     * accent colour in the highlighted month — making Total and Unique identical there and
     * the one-swatch-per-dataset legend wrong. Caught in human QA (Aug rendered red/red).
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_expanded_hit_chart_uses_one_static_colour_per_series_not_a_per_month_array(): void
    {
        $this->actingAsAdmin();

        $widget = new LiveHits;
        $widget->filters = ['date' => 'this_month'];
        $widget->date = 'this_month';
        $widget->expanded = true;

        $datasets = $widget->getData()['datasets'];

        $this->assertCount(2, $datasets);

        foreach ($datasets as $i => $dataset) {
            foreach (['backgroundColor', 'hoverBackgroundColor', 'borderColor', 'hoverBorderColor'] as $key) {
                $this->assertIsString(
                    $dataset[$key],
                    "dataset {$i} '{$key}' must be a single colour; a per-month array reintroduces the "
                    .'current-month highlight and breaks the legend on a two-series chart'
                );
            }
        }

        $this->assertNotSame(
            $datasets[0]['backgroundColor'],
            $datasets[1]['backgroundColor'],
            'the Total and Unique series must be visually distinguishable in every month'
        );
    }

    /**
     * The hit tiles show two numbers, so their help text must say WHICH is which -- the one
     * requirement that does not fall out of test_every_card_carries_its_own_help_text().
     * Added during human QA; each tile now carries its own copy rather than one shared string.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_hit_tiles_explain_both_numbers_in_the_tooltip_and_siblings_keep_the_default(): void
    {
        $this->actingAsAdmin();

        foreach ([LiveHits::class, HistoricalHits::class, StagingLiveHits::class, StagingHistoricalHits::class] as $class) {
            $widget = new $class;
            $widget->filters = ['date' => 'this_month'];
            $widget->date = 'this_month';

            $method = new ReflectionMethod($widget, 'dashboardStatAttributes');
            $method->setAccessible(true);
            $description = $method->invoke($widget, null)['data-description'];

            $this->assertStringContainsString('once per plate', $description, $class.' must say the headline is deduped by plate');
            $this->assertStringContainsString('every hit', $description, $class.' must say the total is not deduped');
            $this->assertStringNotContainsString(
                'smaller number',
                $description,
                $class.' must not say "smaller number" — the headline is the numerically smaller of the two, so it reads backwards'
            );
            $this->assertLessThanOrEqual(2, \substr_count($description, '. ') + 1, $class.' help text must stay within two sentences');
            $this->assertLessThanOrEqual(120, \strlen($description), $class.' help text must stay short enough to read at a glance');
        }

        // The hit tiles' copy lives on four separate cards that share SharedHitStatsData --
        // prove it did not leak onto a sibling that does not. UniqueScanCounts is the
        // DEV-2651 t8 stand-in for the deleted ScanCount as the non-hit-family exemplar.
        $uniqueScanCounts = new UniqueScanCounts;
        $uniqueScanCounts->filters = ['date' => 'this_month'];
        $uniqueScanCounts->date = 'this_month';
        $method = new ReflectionMethod($uniqueScanCounts, 'dashboardStatAttributes');
        $method->setAccessible(true);
        $description = $method->invoke($uniqueScanCounts, null)['data-description'];

        $this->assertNotSame(
            'Click for more details',
            $description,
            'UniqueScanCounts must carry its own help text, not the generic placeholder'
        );
        $this->assertStringNotContainsString(
            'hit',
            \strtolower($description),
            'the hit-tile help text must not leak to siblings'
        );
    }

    /**
     * The generic 'Click for more details' placeholder is the trait default, so a new card
     * ships with it unless its author writes real copy. This is what catches that.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_every_card_carries_its_own_help_text(): void
    {
        $this->actingAsAdmin();

        $descriptions = [];

        foreach (CompanyMetricsOverview::cards() as $key => $class) {
            $widget = new $class;

            if (\property_exists($widget, 'date')) {
                $widget->date = 'this_month';
            }

            if (\property_exists($widget, 'filters')) {
                $widget->filters = ['date_toggles' => 'this_month'];
            }

            $method = new ReflectionMethod($widget, 'getTooltipDescription');
            $method->setAccessible(true);
            $description = $method->invoke($widget);

            $this->assertIsString($description, $key.' must supply help text for its info icon');
            $this->assertNotSame('Click for more details', $description, $key.' still carries the generic placeholder');
            $this->assertLessThanOrEqual(140, \strlen($description), $key.' help text must stay short enough to read at a glance');

            $descriptions[$key] = $description;
        }

        $this->assertSame(
            \count($descriptions),
            \count(\array_unique($descriptions)),
            'each card must explain what IT measures -- duplicate help text means a card was copied without rewriting it'
        );
    }

    private function requireRealClickhouse(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'clickhouse') {
            $this->markTestSkipped('requires a real ClickHouse (CLICKHOUSE_DRIVER=clickhouse)');
        }
    }

    /**
     * Restores whatever `clarity_clickhouse` config the environment actually had before
     * forceClickhouseConnectionToFail() replaced it. Never hardcode a port here: CI runs
     * ClickHouse on 8123 and local QA on 18123, so a literal would silently leave the
     * connection dead in one of them and make the follow-up assertion pass for the wrong
     * reason.
     */
    private function restoreClickhouseConnection(): void
    {
        if ($this->originalClickhouseConfig !== null) {
            Config::set('database.connections.clarity_clickhouse', $this->originalClickhouseConfig);
        }

        DB::purge('clarity_clickhouse');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_card_total_for_this_month_equals_the_seeded_current_month_bucket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $user = $this->actingAsAdmin();

        $widget = $this->makeWidget();
        $this->seedMonthlyBuckets($widget, $user->org_id, [0 => 100]);

        $widget->date = 'this_month';

        $this->assertSame(100, $widget->getCardTotal());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_sparkline_points_yields_thirteen_pairs_for_a_varied_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $user = $this->actingAsAdmin();

        $widget = $this->makeWidget();
        $this->seedMonthlyBuckets($widget, $user->org_id, [
            12 => 10, 11 => 15, 10 => 5, 9 => 20, 8 => 8, 7 => 25, 6 => 12,
            5 => 30, 4 => 3, 3 => 18, 2 => 22, 1 => 40, 0 => 100,
        ]);

        $points = $widget->getSparklinePoints();

        $this->assertNotSame('', $points);

        $pairs = \explode(' ', $points);
        $this->assertCount(13, $pairs);

        foreach ($pairs as $pair) {
            $this->assertMatchesRegularExpression('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $pair);
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_sparkline_points_is_empty_for_a_flat_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $user = $this->actingAsAdmin();

        $widget = $this->makeWidget();
        $this->seedMonthlyBuckets($widget, $user->org_id, \array_fill(0, 13, 42));

        $this->assertSame('', $widget->getSparklinePoints());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_sparkline_points_is_empty_for_an_empty_series(): void
    {
        $this->actingAsAdmin();

        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Empty Series Widget';

            public function getRgbColor(): array
            {
                return [1, 2, 3];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }

            protected function getMonthlyData(bool $formatted = true): array
            {
                return [];
            }
        };

        $this->assertSame('', $widget->getSparklinePoints());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_uses_the_card_total_and_monthly_chart_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $user = $this->actingAsAdmin();

        $widget = $this->makeWidget();
        $this->seedMonthlyBuckets($widget, $user->org_id, [
            12 => 10, 11 => 15, 10 => 5, 9 => 20, 8 => 8, 7 => 25, 6 => 12,
            5 => 30, 4 => 3, 3 => 18, 2 => 22, 1 => 40, 0 => 100,
        ]);
        $widget->date = 'this_month';

        $stat = $widget->toStat();

        $this->assertSame('Test Metric', $stat->getLabel());
        $this->assertSame('100', $stat->getValue());
        $this->assertSame('gray', $stat->getColor());
        $this->assertCount(13, $stat->getChart(), 'sparkline is the existing 13-month getMonthlyData() series');

        $attributes = $stat->getExtraAttributes();
        $this->assertSame('cursor-pointer big-stat-chart', $attributes['class']);
        $this->assertArrayHasKey('data-dashboard-card', $attributes);
        $this->assertSame('Click for more details', $attributes['data-description']);
        $this->assertSame('dashboard-stat-'.$widget->getCardKey(), $stat->getKey(isAbsolute: false));
        $this->assertSame("\$parent.toggleCard('{$widget->getCardKey()}')", $attributes['wire:click']);
        $this->assertStringContainsString("\$wire.\$parent && \$wire.\$parent.expandedCard === '{$widget->getCardKey()}'", $attributes['x-bind:class']);

        $selected = $widget->toStat($widget->getCardKey());
        $this->assertStringContainsString('dashboard-stat-open', $selected->getExtraAttributes()['class']);
        $this->assertSame('true', $selected->getExtraAttributes()['data-dashboard-open']);
        $this->assertArrayNotHasKey('style', $selected->getExtraAttributes());
        $this->assertArrayNotHasKey('data-dashboard-open', $attributes);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_sparkline_is_the_cached_monthly_series_and_does_not_follow_the_date_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00'));

        $user = $this->actingAsAdmin();

        $widget = $this->makeWidget();
        $this->seedMonthlyBuckets($widget, $user->org_id, [
            12 => 10, 11 => 15, 10 => 5, 9 => 20, 8 => 8, 7 => 25, 6 => 12,
            5 => 30, 4 => 3, 3 => 18, 2 => 22, 1 => 40, 0 => 100,
        ]);

        $widget->date = 'this_month';
        $thisMonth = $widget->toStat()->getChart();
        $this->assertCount(13, $thisMonth);
        $this->assertSame(100.0, $thisMonth[12]);

        $widget->date = 'last_month';
        $lastMonth = $widget->toStat()->getChart();
        $this->assertSame($thisMonth, $lastMonth, 'sparkline reuses getMonthlyData(); the date filter only changes the headline');

        $widget->date = 'today';
        $this->assertSame($thisMonth, $widget->toStat()->getChart());
        $this->assertSame(80, $widget->getCardTotal(), 'today/last_7d headlines still use getRangeTotal()');
    }

    /**
     * Expanded ChartWidgets stay on the vendor view. A date-filter refresh must not
     * swap them onto a custom compact card.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_chart_widgets_keep_the_vendor_view_after_a_refresh_dashboard_dispatch(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAsAdmin();

        foreach ([UniqueScanCounts::class, LiveHits::class, RecoveryLeader::class] as $widgetClass) {
            $component = Livewire::test($widgetClass);

            $this->assertSame(
                'filament-widgets::chart-widget',
                (new ReflectionProperty($widgetClass, 'view'))->getValue($component->instance()),
                "{$widgetClass} must render Filament's chart widget view"
            );

            $component->dispatch('refresh-dashboard', date: 'today');

            $this->assertSame(
                'filament-widgets::chart-widget',
                (new ReflectionProperty($widgetClass, 'view'))->getValue($component->instance()),
                "{$widgetClass} must still be on the vendor chart view after refresh-dashboard"
            );
        }

        $component = Livewire::test(UniqueScanCounts::class, ['expanded' => true]);

        $component->dispatch('refresh-dashboard', date: 'today');

        $this->assertSame(
            'filament-widgets::chart-widget',
            (new ReflectionProperty(UniqueScanCounts::class, 'view'))->getValue($component->instance())
        );
    }

    /**
     * LiveHits, HistoricalHits, and StagingHistoricalHits stub getModelQueryForDates()
     * to `return 0`, so the card headline only produces a real number because
     * SharedHitStatsData overrides getRangeTotal() to resolve through the batched
     * hit_stats_daily path. The prior hit-card total/dedupe split moves
     * getCardTotal() onto the deduped-plate series while getTotalHitCount()
     * keeps exposing the previous total -- so this seeds BOTH static memos
     * with DIFFERENT sentinels and asserts each accessor reads its own key. A
     * future cross-wiring regression (either accessor reading the other's key)
     * fails this test instead of passing silently on a shared value.
     *
     * StagingLiveHits::getCardTotal() does not read sharedUniqueRangeStats. DEV-2654
     * points it at StagingLiveHitLeaderQuery::rememberedRange() totals[0], so that
     * widget is seeded through the same rollup cache StagingLiveHitsTest uses.
     * getTotalHitCount() still reads sharedRangeStats.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_hit_widgets_resolve_the_card_total_through_the_shared_batch_seam(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();

        foreach ([LiveHits::class, HistoricalHits::class, StagingHistoricalHits::class] as $widgetClass) {
            cache()->flush();

            $widget = new $widgetClass;
            $widget->date = 'today';

            [$start, $end] = (new ReflectionMethod($widget, 'resolveCardRange'))->invoke($widget);
            $rangeKey = "{$user->org_id}:{$start->toDateTimeString()}:{$end->toDateTimeString()}";

            // Pre-seed the trait's per-class range memos under the exact keys the two seams look up.
            $totalMemo = new ReflectionProperty($widgetClass, 'sharedRangeStats');
            $totalMemo->setAccessible(true);
            $totalMemo->setValue(null, [
                $rangeKey => [$widget->getHitStatsColumn() => 555],
            ]);

            $uniqueMemo = new ReflectionProperty($widgetClass, 'sharedUniqueRangeStats');
            $uniqueMemo->setAccessible(true);
            $uniqueMemo->setValue(null, [
                $rangeKey => [$widget->getHitStatsColumn() => 222],
            ]);

            $this->assertSame(
                222,
                $widget->getCardTotal(),
                "{$widgetClass}::getCardTotal() must resolve the DEDUPED plate count (DEV-2406 AC-1), not the total"
            );

            $this->assertSame(
                555,
                $widget->getTotalHitCount(),
                "{$widgetClass}::getTotalHitCount() must resolve the previous total via the unchanged hit_stats_daily seam"
            );
        }

        cache()->flush();

        $stagingLive = new StagingLiveHits;
        $stagingLive->date = 'today';

        [$start, $end] = (new ReflectionMethod($stagingLive, 'resolveCardRange'))->invoke($stagingLive);
        $rangeKey = "{$user->org_id}:{$start->toDateTimeString()}:{$end->toDateTimeString()}";

        $totalMemo = new ReflectionProperty(StagingLiveHits::class, 'sharedRangeStats');
        $totalMemo->setAccessible(true);
        $totalMemo->setValue(null, [
            $rangeKey => [$stagingLive->getHitStatsColumn() => 555],
        ]);

        [$rollupStart, $rollupEnd, $timezone] = (new ReflectionMethod($stagingLive, 'resolveRangeAndTimezone'))->invoke($stagingLive);

        cache()->put(
            StagingLiveHitLeaderQuery::sharedRangeCacheKey((int) $user->org_id, $rollupStart, $rollupEnd, $timezone),
            ['totals' => [0 => 222], 'names' => []],
            now()->addMinutes(10),
        );

        $this->assertSame(
            222,
            $stagingLive->getCardTotal(),
            'StagingLiveHits::getCardTotal() must resolve the rollup totals[0] plate count, not sharedUniqueRangeStats'
        );

        $this->assertSame(
            555,
            $stagingLive->getTotalHitCount(),
            'StagingLiveHits::getTotalHitCount() must resolve the previous total via the unchanged hit_stats_daily seam'
        );
    }

    /**
     * Direct regression guard for the DEV-2324 defect this ticket is at risk of
     * reintroducing: the unique series must live under its OWN cache-key namespace,
     * never the total's. Seeding the two keys with different values and reading both
     * back proves neither resolver wrote into (or read from) the other's key.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_unique_and_total_monthly_series_use_separate_cache_namespaces(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        $widget = new LiveHits;

        // Seed all four widget prefixes for every one of the 13 months PLUS the
        // corresponding `:unique:` keys, so neither resolver falls through to a real
        // ClickHouse call (which would be swallowed to [] in the testing env and mask
        // a false green).
        for ($i = 12; $i >= 1; $i--) {
            $monthKey = now()->startOfMonth()->subMonths($i)->format('Y-m');

            foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
                $totalValue = $monthKey === '2026-07' ? 700 : 1;
                $uniqueValue = $monthKey === '2026-07' ? 300 : 1;

                cache()->put("{$prefix}:{$monthKey}:org={$user->org_id}", $totalValue);
                cache()->put("{$prefix}:unique:{$monthKey}:org={$user->org_id}", $uniqueValue);
            }
        }

        foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
            cache()->put("{$prefix}:this_month:org={$user->org_id}", 1);
            cache()->put("{$prefix}:unique:this_month:org={$user->org_id}", 1);
        }

        $totalSeries = (new ReflectionMethod($widget, 'getMonthlyData'))->invoke($widget, false);
        $uniqueSeries = (new ReflectionMethod($widget, 'getUniqueMonthlyData'))->invoke($widget, false);

        $julyIndex = 11; // index = 12 - $i; July 2026 is $i=1 month before August 2026's "this_month".
        $this->assertSame(700, $totalSeries[$julyIndex], 'the total series must read the total key, not the unique key');
        $this->assertSame(300, $uniqueSeries[$julyIndex], 'the unique series must read the unique key, not the total key');

        $this->assertSame(
            700,
            cache()->get("live-hits:2026-07:org={$user->org_id}"),
            'resolving the unique series must not overwrite the total key'
        );
        $this->assertSame(
            300,
            cache()->get("live-hits:unique:2026-07:org={$user->org_id}"),
            'resolving the total series must not overwrite the unique key'
        );
    }

    /**
     * AC-5: getDescription() must describe the SAME metric as getCardTotal() -- the
     * deduped unique series -- not the raw total.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_description_renders_the_unique_series_not_the_total(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        $widget = new LiveHits;

        for ($i = 12; $i >= 1; $i--) {
            $monthKey = now()->startOfMonth()->subMonths($i)->format('Y-m');

            foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
                cache()->put("{$prefix}:{$monthKey}:org={$user->org_id}", 999);
                cache()->put("{$prefix}:unique:{$monthKey}:org={$user->org_id}", 1);
            }
        }

        foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
            cache()->put("{$prefix}:this_month:org={$user->org_id}", 999);
            cache()->put("{$prefix}:unique:this_month:org={$user->org_id}", 50);
        }

        $widget->date = 'this_month';

        $description = (string) $widget->getDescription();

        $this->assertStringContainsString('50', $description, 'getDescription() must use the unique series, not the total');
        $this->assertStringNotContainsString('999', $description);
    }

    /**
     * Round-1 review FIX 2 (kept from round 1): Carbon::createFromFormat('Y-m',
     * $monthKey, $tz) inherits TODAY's day-of-month, overflowing a shorter
     * target month once today is the 29th/30th/31st. monthKeyStart() anchors
     * the day to `01` first so this can't happen. Driver-proved: today=2026-08-31,
     * month=2026-06 must resolve to 2026-06-01, not 2026-07-01.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_month_key_start_does_not_overflow_when_today_is_the_31st(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');

        $this->actingAsAdmin();
        $widget = new LiveHits;

        $resolved = (new ReflectionMethod($widget, 'monthKeyStart'))->invoke($widget, '2026-06', 'UTC');

        $this->assertSame('2026-06-01', $resolved->format('Y-m-d'));
    }

    /**
     * Round-3 design point 4: ScanCount didn't use SharedHitStatsData (and deliberately
     * didn't share its monthKeyStart() helper). DEV-2651 t8 deleted ScanCount and ported
     * its raw-total series onto UniqueScanCounts, which carries a verbatim copy of the
     * same local mirror -- it must resolve the same way: driver-proved, today=2026-08-31,
     * '2026-06' must resolve to 2026-06-01, not overflow to 2026-07-01.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_unique_scan_counts_month_key_start_does_not_overflow_when_today_is_the_31st(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');

        $this->actingAsAdmin();
        $widget = new UniqueScanCounts;

        $resolved = (new ReflectionMethod($widget, 'monthKeyStart'))->invoke($widget, '2026-06', 'UTC');

        $this->assertSame('2026-06-01', $resolved->format('Y-m-d'));
    }

    /**
     * Round-3 design point 1+2: getSharedUniqueHitStatsForRange() now returns
     * `null` (never a zeroed array) on a genuine fetch failure, so no caller
     * can persist a fabricated 0 under a shared cache key. Forces a REAL
     * ClickHouse connect failure (dead port) rather than relying on
     * assertDatetime()'s InvalidArgumentException, so this proves the
     * try/catch around handleRange() itself, not just input validation.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_shared_unique_hit_stats_for_range_returns_null_on_a_failed_fetch(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAsAdmin();
        $widget = new LiveHits;

        $this->forceClickhouseConnectionToFail();

        $result = (new ReflectionMethod($widget, 'getSharedUniqueHitStatsForRange'))
            ->invoke($widget, '2026-08-01 00:00:00', '2026-08-12 23:59:59');

        $this->assertNull($result, 'a failed range fetch must return null, never a zeroed array a caller could cache');
    }

    /**
     * Round-3 design point 2: a failed unique range fetch must not write any
     * cache entry under the shared `unique:<date>` key that BOTH getCardTotal()
     * and getDescription() read -- and a subsequent call must retry (not read
     * back a cached, silently-wrong 0).
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_current_period_unique_does_not_cache_a_failed_fetch(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        $widget = new LiveHits;
        $widget->date = 'today';

        $this->forceClickhouseConnectionToFail();

        $displayed = (new ReflectionMethod($widget, 'currentPeriodUnique'))->invoke($widget);
        $this->assertSame(0, $displayed, 'a failed fetch still renders 0 for display, uncached');

        $cacheKey = (new ReflectionMethod($widget, 'buildWidgetCacheKey'))->invoke($widget, 'unique:today', $user->org_id);
        $this->assertNull(
            cache()->get($cacheKey),
            'a failed fetch must not persist a zeroed value under the shared unique:<date> cache key'
        );

        // Because nothing was cached, a second call re-fetches -- proven by
        // seeding the cache key directly (as a successful fetch would have)
        // and confirming currentPeriodUnique() now reads it back instead of
        // going through the (still-failing) ClickHouse seam again.
        cache()->put($cacheKey, 42, now()->addMinutes(10));

        $this->assertSame(
            42,
            (new ReflectionMethod($widget, 'currentPeriodUnique'))->invoke($widget),
            'once a value is cached (by a later successful fetch), currentPeriodUnique() must read it back'
        );
    }

    /**
     * Round-3 design point 2: resolveCurrentUniqueMonth() mirrors the same
     * do-not-cache-on-failure discipline for the 'this_month' bucket -- a failed
     * fetch must not write the cache key that itself doubles as this method's
     * own "already resolved" guard.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_resolve_current_unique_month_does_not_cache_a_failed_fetch(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        $widget = new LiveHits;

        $this->forceClickhouseConnectionToFail();

        (new ReflectionMethod($widget, 'resolveCurrentUniqueMonth'))->invoke($widget, $user->org_id, 'UTC');

        $this->assertNull(
            cache()->get("live-hits:unique:this_month:org={$user->org_id}"),
            'a failed current-month fetch must not cache a fabricated 0'
        );
    }

    /**
     * Round-3 design point 3: a failed past-months fetch (a genuine Throwable
     * from HitPlateStatsBatchQuery::handle(), not merely an empty result) must
     * write NO data values, must NOT set the sharedUniqueMonthlyStats
     * completion guard, but MUST set the short negative-cache marker -- and a
     * second immediate call must short-circuit on that marker without
     * re-querying (proven by seeding a marker directly and confirming no cache
     * writes occur even though every month is uncached).
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_resolve_past_unique_months_on_a_failed_fetch_writes_no_data_but_sets_the_negative_cache_marker(): void
    {
        $this->requireRealClickhouse();

        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        $widget = new LiveHits;

        $this->forceClickhouseConnectionToFail();

        (new ReflectionMethod($widget, 'resolvePastUniqueMonths'))->invoke($widget, $user->org_id, 'UTC');

        $monthKey = now()->startOfMonth()->subMonths(6)->format('Y-m');
        $this->assertNull(
            cache()->get("live-hits:unique:{$monthKey}:org={$user->org_id}"),
            'a failed fetch must not leave a fabricated 0 cached under the one-year TTL'
        );

        $guard = new ReflectionProperty(LiveHits::class, 'sharedUniqueMonthlyStats');
        $guard->setAccessible(true);
        $this->assertArrayNotHasKey(
            $user->org_id,
            $guard->getValue(null),
            'the completion guard must not be set on a failed fetch, so a later request retries'
        );

        $markerKey = (new ReflectionMethod($widget, 'uniqueFetchFailureMarkerKey'))->invoke($widget, $user->org_id, 'past-months');
        $this->assertNotNull(
            cache()->get($markerKey),
            'a failed fetch must set the short negative-cache marker so a hard-down ClickHouse is not hammered'
        );

        // Restore a live ClickHouse connection, then call again immediately.
        // If the marker did NOT short-circuit, this second call would reach a
        // real, working ClickHouse and cache a genuine 0 for the org (no rows
        // match); asserting it's still null proves the marker -- not luck --
        // is what prevented the query.
        $this->restoreClickhouseConnection();

        (new ReflectionMethod($widget, 'resolvePastUniqueMonths'))->invoke($widget, $user->org_id, 'UTC');

        $this->assertNull(
            cache()->get("live-hits:unique:{$monthKey}:org={$user->org_id}"),
            'while the negative-cache marker is present, resolvePastUniqueMonths() must short-circuit without querying'
        );
    }

    /**
     * Round-3 design point 3: the throttle's other half -- a SUCCESSFUL but
     * genuinely empty past-months fetch (no exception, no rows) DOES cache 0
     * under the long TTL. This is what stops a zero-hit org from re-running the
     * full 13-partition scan on every render; it is the opposite of the
     * failure case above and must not be confused with it.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_resolve_past_unique_months_caches_zero_on_a_successful_but_empty_fetch(): void
    {
        $this->requireRealClickhouse();

        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        $widget = new LiveHits;

        // Real ClickHouse connection (not forced to fail); a fresh, unseeded
        // org has no matching hits_cold rows for any of the 12 past months, so
        // HitPlateStatsBatchQuery::handle() succeeds and returns [].
        (new ReflectionMethod($widget, 'resolvePastUniqueMonths'))->invoke($widget, $user->org_id, 'UTC');

        $monthKey = now()->startOfMonth()->subMonths(6)->format('Y-m');
        $this->assertSame(
            0,
            cache()->get("live-hits:unique:{$monthKey}:org={$user->org_id}"),
            'a successful, genuinely empty fetch must cache a real 0, restoring the re-scan throttle'
        );

        $guard = new ReflectionProperty(LiveHits::class, 'sharedUniqueMonthlyStats');
        $guard->setAccessible(true);
        $this->assertArrayHasKey(
            $user->org_id,
            $guard->getValue(null),
            'the completion guard must be set on a successful fetch, empty or not'
        );

        $markerKey = (new ReflectionMethod($widget, 'uniqueFetchFailureMarkerKey'))->invoke($widget, $user->org_id, 'past-months');
        $this->assertNull(cache()->get($markerKey), 'a successful fetch must never set the failure marker');
    }

    /**
     * DEV-2406 review fix: four hit widgets (LiveHits, StagingLiveHits,
     * HistoricalHits, StagingHistoricalHits) each `use SharedHitStatsData`, and
     * PHP gives a trait's static properties a SEPARATE copy per using class --
     * proven directly below by seeding one class's copy and confirming a
     * sibling class's copy is untouched. This is the actual root cause of the
     * 4x amplification: `getSharedUniqueHitStatsForRange()`'s per-class
     * `static::$sharedUniqueRangeStats` memo cannot dedupe the underlying
     * `uniqExact ... FINAL` fetch across the four widgets a single dashboard
     * render resolves, because each widget class's copy is a SEPARATE array.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_trait_static_properties_are_not_shared_across_the_four_widget_classes(): void
    {
        $memo = new ReflectionProperty(LiveHits::class, 'sharedUniqueRangeStats');
        $memo->setAccessible(true);
        $memo->setValue(null, ['probe' => ['live_hit_count' => 999]]);

        $siblingMemo = new ReflectionProperty(HistoricalHits::class, 'sharedUniqueRangeStats');
        $siblingMemo->setAccessible(true);

        $this->assertArrayNotHasKey(
            'probe',
            $siblingMemo->getValue(null),
            'a trait static property seeded on LiveHits must not appear on HistoricalHits -- each using class gets its own copy'
        );

        $memo->setValue(null, []);
    }

    /**
     * Direct regression guard for the 4x amplification fix. The underlying
     * ClickHouse driver (insight-lpr/laravel-clickhouse-eloquent) never fires
     * Laravel's `QueryExecuted` event, so `DB::listen()` cannot count real
     * fetches here -- instead this seeds the genuinely cross-widget-class
     * cache key `sharedUniqueRangeMemoKey()` resolves to with a sentinel value
     * no real ClickHouse fetch against a brand-new, unseeded org could ever
     * produce (424242), after the FIRST widget populates it for real. If any
     * of the three sibling widgets (different classes, each with their OWN
     * per-class `static::$sharedUniqueRangeStats` copy) still queried
     * ClickHouse independently instead of reading this key, it would render 0
     * (the real, unseeded-org answer), not the sentinel.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_current_period_unique_shares_one_underlying_fetch_across_all_four_hit_widgets(): void
    {
        $this->requireRealClickhouse();

        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        cache()->flush();

        $probe = new LiveHits;
        $probe->date = 'today';

        [$start, $end] = (new ReflectionMethod($probe, 'resolveCardRange'))->invoke($probe);

        $sharedMemoKey = (new ReflectionMethod($probe, 'sharedUniqueRangeMemoKey'))
            ->invoke($probe, $user->org_id, $start->toDateTimeString(), $end->toDateTimeString());

        $this->assertNull(cache()->get($sharedMemoKey), 'sanity: nothing cached yet');

        // The FIRST widget resolves for real -- this is the one genuine
        // ClickHouse fetch for a brand-new org (which has no hits, so it
        // renders 0).
        $this->assertSame(0, (new ReflectionMethod($probe, 'currentPeriodUnique'))->invoke($probe));

        $resolvedResult = cache()->get($sharedMemoKey);
        $this->assertIsArray(
            $resolvedResult,
            'the first widget must populate a cache key shared across ALL FOUR widget classes, not just its own per-class static'
        );

        // Overwrite the shared key with a sentinel a real fetch could never
        // produce, then prove the remaining three widgets (different classes)
        // resolve THIS key instead of issuing their own independent fetch.
        cache()->put(
            $sharedMemoKey,
            \array_fill_keys(\array_keys($resolvedResult), 424242),
            now()->addMinute()
        );

        foreach ([new StagingLiveHits, new HistoricalHits, new StagingHistoricalHits] as $widget) {
            $widget->date = 'today';

            $this->assertSame(
                424242,
                (new ReflectionMethod($widget, 'currentPeriodUnique'))->invoke($widget),
                \get_class($widget).' must resolve through the cross-widget shared memo instead of issuing its own ClickHouse fetch'
            );
        }
    }

    /**
     * The other half of the amplification bug: with ClickHouse hard down, the
     * negative-cache marker was keyed per widget PREFIX, so all four widgets
     * still each issued their own query before setting their own marker.
     * Re-keyed shared (no widget prefix), the FIRST widget's failed fetch sets
     * ONE marker; the remaining three (different classes) must short-circuit
     * on it. Proven by restoring a REAL, working ClickHouse connection after
     * the first widget fails, then confirming the sibling widgets still
     * render 0 UNCACHED -- if the marker were per-widget, they would reach the
     * now-healthy connection and cache a genuine value instead.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_current_period_unique_with_clickhouse_down_shares_one_marker_across_all_four_hit_widgets(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        cache()->flush();

        $this->forceClickhouseConnectionToFail();

        $first = new LiveHits;
        $first->date = 'today';
        $this->assertSame(0, (new ReflectionMethod($first, 'currentPeriodUnique'))->invoke($first));

        $this->restoreClickhouseConnection();

        foreach ([new StagingLiveHits, new HistoricalHits, new StagingHistoricalHits] as $widget) {
            $widget->date = 'today';

            $displayed = (new ReflectionMethod($widget, 'currentPeriodUnique'))->invoke($widget);
            $this->assertSame(
                0,
                $displayed,
                \get_class($widget).' must still short-circuit on the shared marker LiveHits already set'
            );

            $cacheKey = (new ReflectionMethod($widget, 'buildWidgetCacheKey'))->invoke($widget, 'unique:today', $user->org_id);
            $this->assertNull(
                cache()->get($cacheKey),
                \get_class($widget).' must not cache a value while the shared marker is blocking the fetch'
            );
        }
    }

    /**
     * DEV-2406 review fix: the today/last_7d RANGE path had NO negative cache
     * at all -- unlike resolvePastUniqueMonths(), a failed
     * getSharedUniqueHitStatsForRange() call wrote no marker, so every render
     * re-attempted the dead-port connection. This pins the marker itself
     * (rather than timing) so a future regression that silently drops the
     * write is caught directly.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_shared_unique_hit_stats_for_range_sets_a_negative_cache_marker_on_a_failed_fetch(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsAdmin();
        cache()->flush();

        $widget = new LiveHits;

        $this->forceClickhouseConnectionToFail();

        $start = '2026-08-01 00:00:00';
        $end = '2026-08-12 23:59:59';

        $result = (new ReflectionMethod($widget, 'getSharedUniqueHitStatsForRange'))->invoke($widget, $start, $end);
        $this->assertNull($result, 'a failed range fetch must return null');

        $markerKey = (new ReflectionMethod($widget, 'uniqueFetchFailureMarkerKey'))
            ->invoke($widget, $user->org_id, "range:{$start}:{$end}");

        $this->assertNotNull(
            cache()->get($markerKey),
            'a failed RANGE fetch must set a negative-cache marker, mirroring resolvePastUniqueMonths()'
        );

        // A sibling widget class must read the SAME marker key -- and a second
        // call (even from a different widget class) must short-circuit on it
        // rather than reattempting the dead-port connection.
        $sibling = new HistoricalHits;
        $siblingMarkerKey = (new ReflectionMethod($sibling, 'uniqueFetchFailureMarkerKey'))
            ->invoke($sibling, $user->org_id, "range:{$start}:{$end}");

        $this->assertSame($markerKey, $siblingMarkerKey, 'the negative-cache marker must be keyed the same way regardless of widget class');

        $this->restoreClickhouseConnection();

        $secondResult = (new ReflectionMethod($sibling, 'getSharedUniqueHitStatsForRange'))->invoke($sibling, $start, $end);
        $this->assertNull(
            $secondResult,
            'while the negative-cache marker is present, a sibling widget must short-circuit without querying'
        );
    }
}
