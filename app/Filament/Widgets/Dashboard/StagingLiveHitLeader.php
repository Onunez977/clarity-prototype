<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\HitPlateStatsBatchQuery;
use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\BuildsDateFilterChartSeries;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\RendersDashboardStat;
use App\Filament\Widgets\Dashboard\Concerns\ResolvesDashboardCacheTtl;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Deliberately does NOT `use DashboardWidget` or `SharedHitStatsData` -- both model a
 * single scalar per month, which cannot represent a ranked list of kits. Also, a 5th
 * class `use`-ing SharedHitStatsData would get its OWN copy of the trait's static
 * `$sharedUniqueMonthlyStats` (trait statics are per-USING-class, not shared), which
 * would cause a 5th ClickHouse scan instead of collapsing into the existing four.
 *
 * The sparkline is deliberately NOT the ranked list. Per-kit uniqExactIf values do
 * not sum to the org total. It reads the SAME cross-card cache keys
 * `App\Filament\Widgets\Dashboard\Concerns\SharedHitStatsData` writes for the
 * `staging-live-hits` deduped series, so this card's sparkline and the sibling
 * StagingLiveHits card's headline can never disagree.
 *
 * It FILLS those shared keys itself (for all four `SharedHitStatsData::$hitWidgetMap`
 * prefixes, not just its own) rather than relying on a sibling to have warmed them.
 * That is deliberately independent of grid position: chartSeries() calls
 * resolveSharedMonths()/resolveSharedCurrentMonth() before reading the cache, and both
 * are no-ops once the keys exist -- so whichever of this card and the four hit tiles
 * renders first on a cold cache pays for the batch and the rest read it back.
 */
class StagingLiveHitLeader extends ChartWidget implements DashboardMetricCard, ExplainsCardQuery
{
    use BuildsDateFilterChartSeries;
    use RendersDashboardStat;
    use ResolvesDashboardCacheTtl;
    use StatHelper;

    private const UNIQUE_NAMESPACE = 'unique:';

    /**
     * This card's own metric among the four shared hit prefixes, named ONCE.
     *
     * It is both resolveSharedCurrentMonth()'s resolved-guard key and chartSeries()'s read key. Left
     * hand-spelled at each site, a prefix rename would still pass the SHARED_PREFIX_MAP drift
     * assertion while (a) making chartSeries() read a key nobody writes -- a permanently flat-zero
     * sparkline -- and (b) leaving the guard on a key nobody ever writes, so it never fires and the
     * card issues an unbounded range query against hits_cold on EVERY render. Asserted to be a key
     * of SHARED_PREFIX_MAP by the drift test.
     */
    private const STAGING_LIVE_PREFIX = 'staging-live-hits';

    /**
     * Mirrors `SharedHitStatsData::$hitWidgetMap` -- the four prefix/column pairs every
     * one of those keys is written under. Kept as an independent copy rather than a
     * shared constant: this class does not use that trait, and the four keys it writes
     * must match theirs by VALUE (the cache is the contract), not by shared code.
     */
    private const SHARED_PREFIX_MAP = [
        'live-hits' => 'live_hit_count',
        'historical-hits' => 'historical_hit_count',
        'staging-live-hits' => 'staging_live_hit_count',
        'staging-historical-hits' => 'staging_historical_hit_count',
    ];

    private const SHARED_FETCH_FAILURE_TTL_SECONDS = 60;

    protected ?string $heading = 'Staging Live Hit Leaderboard';

    protected function getTooltipDescription(): ?string
    {
        return 'Your top kit by staging live hits in this period. Click to see the full ranking.';
    }

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public ?string $date = 'this_month';

    public bool $expanded = false;

    /**
     * @var array<string, array>
     */
    protected array $monthlyDataMemo = [];

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 1,
    ];

    #[Url]
    public mixed $filters;

    /** @var array<int, true> */
    protected static array $sharedSparklineResolved = [];

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderWidth: 1,
                        cornerRadius: 6,
                        padding: 8,
                        displayColors: true,
                        boxWidth: 10,
                        boxHeight: 10,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        borderColor: 'rgba(245, 158, 11, 1)',
                        position: 'nearest',
                        yAlign: 'top',
                        xAlign: 'center',
                        filter: (item) => item.raw !== null && item.raw !== 0,
                        itemSort: (a, b) => b.datasetIndex - a.datasetIndex,
                    },
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                hover: {
                    mode: 'index',
                    intersect: false,
                    animationDuration: 200,
                },
                animation: {
                    duration: 750,
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        stacked: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                        },
                        ticks: {
                            maxTicksLimit: 8,
                        },
                    },
                    x: {
                        stacked: true,
                        grid: {
                            display: false,
                        },
                    },
                },
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 3,
            }
        JS);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);

        $this->date = $this->filters['date_toggles'] ?? 'this_month';
    }

    protected function getMaxHeight(): ?string
    {
        return $this->expanded ? '600px' : '12rem';
    }

    #[On('refresh-dashboard')]
    public function refreshData(string $date): void
    {
        $this->date = $date;
        $this->monthlyDataMemo = [];
    }

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }

    public function getCardKey(): string
    {
        return 'staging-live-hit-leader';
    }

    /**
     * DEV-2586: sorted rather than `max()`-ed so the displayed total and the displayed
     * name come from the SAME row -- they must never be able to disagree.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        $leader = collect($this->leaderboardForSelectedRange())->sortByDesc('total')->first();

        return $this->dashboardStat(
            Stat::make($this->getCardLabel(), \number_format($leader['total'] ?? 0))
                ->description($this->leaderKitDescription($leader['name'] ?? null))
                ->chart($this->chartSeries())
                ->color('warning'),
            $selectedCard,
        );
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getData(): array
    {
        $leaderboard = $this->leaderboardForSelectedRange();

        return [
            'datasets' => [
                $this->makeDataset('Staging Live Hits', collect($leaderboard)->pluck('total')->all()),
            ],
            'labels' => collect($leaderboard)->pluck('name')->all(),
        ];
    }

    public function getDescription(): string|Htmlable|null
    {
        $leaderboard = collect($this->leaderboardForSelectedRange())->sortByDesc('total')->values();

        $leader = $leaderboard->first();
        $runnerUp = $leaderboard->get(1);

        $label = match ($this->date) {
            'today' => 'Today',
            'last_7d' => 'Last 7 Days',
            'last_month' => 'Last Month',
            default => 'This Month',
        };

        return new HtmlString(
            "Leader for {$label}: <b>".e($leader['name'] ?? 'N/A').'</b> with '.\number_format($leader['total'] ?? 0).
            "<br/>Runner-up for {$label}: <b>".e($runnerUp['name'] ?? 'N/A').'</b> with '.\number_format($runnerUp['total'] ?? 0)
        );
    }

    /**
     * Top-10 per-kit ranking for the page date filter. Every arm, including this month,
     * reads one {@see StagingLiveHitLeaderQuery::rememberedRange()} window. Key 0 is the
     * org total and is dropped before the sort.
     *
     * @return list<array{id: int, name: string, total: int}>
     */
    public function leaderboardForSelectedRange(): array
    {
        $orgId = (int) auth()->user()->org_id;
        [$start, $end, $timezone] = $this->resolveRangeAndTimezone();
        $rollup = $this->fetchRangeRollup($orgId, $start, $end, $timezone);

        $totals = $rollup['totals'];
        $names = $rollup['names'];
        unset($totals[0]);

        $leaderboard = [];
        foreach ($totals as $kitId => $total) {
            $leaderboard[] = [
                'id' => (int) $kitId,
                'name' => $names[$kitId],
                'total' => (int) $total,
            ];
        }

        \usort($leaderboard, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: $a['id'] <=> $b['id']);

        return \array_slice($leaderboard, 0, 10);
    }

    /**
     * The viewer zone is sanitised before any Carbon window is cut. Month arms
     * still share the bucket zone from {@see StagingLiveHitLeaderQuery::boundsForCardDate()}.
     * Day arms receive that same sanitised zone so `now(tz:)` cannot throw.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function resolveRangeAndTimezone(): array
    {
        $viewer = $this->sanitizedViewerTimezone();
        [$start, $end] = $this->resolveCardRange();

        return StagingLiveHitLeaderQuery::boundsForCardDate(
            (string) $this->date,
            $viewer,
            $start->toDateTimeString(),
            $end->toDateTimeString(),
        );
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    protected function resolveCardRange(): array
    {
        $timezone = $this->sanitizedViewerTimezone();

        return match ($this->date) {
            'today' => [
                now(tz: $timezone)->startOfDay(),
                now(tz: $timezone)->endOfDay(),
            ],
            'last_7d' => [
                now(tz: $timezone)->subDays(7)->startOfDay(),
                now(tz: $timezone)->endOfDay(),
            ],
            'last_month' => [
                now(tz: $timezone)->startOfMonth()->subMonth()->startOfDay(),
                now(tz: $timezone)->startOfMonth()->subMonth()->endOfMonth()->endOfDay(),
            ],
            default => [
                now(tz: $timezone)->startOfMonth()->startOfDay(),
                now(tz: $timezone)->endOfDay(),
            ],
        };
    }

    private function sanitizedViewerTimezone(?string $timezone = null): string
    {
        return ScanPlateStatsBatchQuery::safeTimezone((string) ($timezone ?? (auth()->user()->timezone ?? 'UTC')));
    }

    /**
     * Overridable so a test can count invocations. `DB::listen()` never fires for the
     * ClickHouse driver.
     *
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        return StagingLiveHitLeaderQuery::rememberedRange($orgId, $start, $end, $timezone);
    }

    /**
     * @return array<string, array<string, int>>
     */
    protected function fetchSharedMonths(int $orgId, string $start, string $end): array
    {
        return HitPlateStatsBatchQuery::handle([
            'org_id' => $orgId,
            'start' => $start,
            'end' => $end,
        ]);
    }

    /**
     * @return array<string, int>
     */
    protected function fetchSharedCurrentMonth(int $orgId, string $start, string $end): array
    {
        return HitPlateStatsBatchQuery::handleRange([
            'org_id' => $orgId,
            'start' => $start,
            'end' => $end,
        ]);
    }

    /**
     * Anchors a `Y-m` month key to its first day before any month arithmetic runs --
     * `Carbon::createFromFormat('Y-m', $monthKey, $tz)` inherits TODAY's day-of-month, which
     * overflows a target month with fewer days than today's. See
     * `SharedHitStatsData::monthKeyStart()`, mirrored verbatim here.
     */
    protected function monthKeyStart(string $monthKey, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $monthKey.'-01', $this->sanitizedViewerTimezone($timezone));
    }

    /**
     * Deliberately the SAME key format `SharedHitStatsData::uniqueFetchFailureMarkerKey()`
     * writes for its 'past-months' scope -- reusing it means a failure this card observes
     * also throttles the four hit widgets' own past-months resolver (and vice versa),
     * instead of five independent negative-cache markers for what is the same underlying
     * "is ClickHouse reachable" question.
     */
    private function sharedFailureMarkerKey(int $orgId): string
    {
        return 'hit-plate-stats:unique-fetch-failed:past-months:org='.$orgId;
    }

    /**
     * The RANGE-scoped sibling of sharedFailureMarkerKey(), byte-identical to what
     * SharedHitStatsData::uniqueFetchFailureMarkerKey($orgId, "range:{$start}:{$end}") produces.
     *
     * The trait takes a $scope for exactly one reason, stated in its own docblock: to keep the
     * past-months BATCH failure separate from a specific RANGE failure. Filing a range failure under
     * `past-months` breaks that in both directions -- a failed 12-month batch would suppress the
     * current-month range fetch this card needs (flat-zero sparkline while the sibling card
     * publishes the real number), and a failed range would throttle all four hit cards'
     * past-months resolver over a query none of them issued.
     */
    private function sharedRangeFailureMarkerKey(int $orgId, string $start, string $end): string
    {
        return 'hit-plate-stats:unique-fetch-failed:range:'.$start.':'.$end.':org='.$orgId;
    }

    /**
     * @return array<string, int>
     */
    private function collectUncachedSharedMonths(int $orgId, string $timezone): array
    {
        $uncached = [];
        $timezone = $this->sanitizedViewerTimezone($timezone);

        // PAST months only ($i >= 1), exactly like SharedHitStatsData::collectUncachedMonths().
        // The current month is deliberately NOT batched: the trait resolves it from a
        // timezone-bounded RANGE query (now(tz)->startOfMonth() .. now(tz)->endOfDay()), and
        // toStartOfMonth()'s whole-calendar-month bucket is a DIFFERENT window. Filling
        // `this_month` from the batch would publish a larger number than the trait's for every
        // negative-UTC-offset org, into keys the staging-live-hits card reads -- changing that
        // card's headline depending on entitlement. Whichever of the two renders first wins the
        // key, so this must not depend on grid order. See resolveSharedCurrentMonth().
        for ($i = 12; $i >= 1; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');

            foreach (\array_keys(self::SHARED_PREFIX_MAP) as $prefix) {
                if (cache()->get($prefix.':'.self::UNIQUE_NAMESPACE.$monthKey.':org='.$orgId) === null) {
                    $uncached[$monthKey] = $i;

                    break;
                }
            }
        }

        return $uncached;
    }

    /**
     * Fills the SharedHitStatsData cross-card cache keys this card's sparkline reads,
     * for all four `SHARED_PREFIX_MAP` prefixes, in ONE HitPlateStatsBatchQuery::handle()
     * round trip spanning the gap's min..max month -- never HitPlateStatsBatchQuery per
     * widget, and never 13 separate calls. The current-month bucket is written under the
     * literal 'this_month' key (matching SharedHitStatsData::buildMonthlyDataFromCache()),
     * not its 'Y-m' string.
     */
    private function resolveSharedMonths(int $orgId, string $timezone): void
    {
        if (isset(static::$sharedSparklineResolved[$orgId])) {
            return;
        }

        if (cache()->get($this->sharedFailureMarkerKey($orgId)) !== null) {
            return;
        }

        $timezone = $this->sanitizedViewerTimezone($timezone);
        $uncachedMonths = $this->collectUncachedSharedMonths($orgId, $timezone);

        if (! empty($uncachedMonths)) {
            $sortedKeys = array_keys($uncachedMonths);
            sort($sortedKeys);
            $minMonth = $sortedKeys[0];
            $maxMonth = end($sortedKeys);

            $batchStart = $this->monthKeyStart($minMonth, $timezone)->startOfDay()->toDateTimeString();
            $batchEnd = $this->monthKeyStart($maxMonth, $timezone)->endOfMonth()->endOfDay()->toDateTimeString();

            try {
                $batchResult = $this->fetchSharedMonths($orgId, $batchStart, $batchEnd);
            } catch (\Throwable $e) {
                if (\in_array(config('app.env'), ['production', 'testing'])) {
                    report($e);

                    cache()->put($this->sharedFailureMarkerKey($orgId), true, self::SHARED_FETCH_FAILURE_TTL_SECONDS);

                    return;
                }

                throw $e;
            }

            foreach ($uncachedMonths as $monthKey => $index) {
                $pastTTL = $this->baseDashboardCacheTTL(
                    $index,
                    $this->monthKeyStart($monthKey, $timezone)->endOfMonth(),
                );

                foreach (self::SHARED_PREFIX_MAP as $prefix => $col) {
                    $value = $batchResult[$monthKey][$col] ?? 0;

                    cache()->put($prefix.':'.self::UNIQUE_NAMESPACE.$monthKey.':org='.$orgId, $value, $pastTTL);
                }
            }
        }

        static::$sharedSparklineResolved[$orgId] = true;
    }

    /**
     * Mirror of SharedHitStatsData::resolveCurrentUniqueMonth() -- the current month is
     * resolved from a timezone-bounded RANGE query, never from the batch.
     *
     * Why this exists rather than folding the current month into resolveSharedMonths():
     * `HitPlateStatsBatchQuery::handle()` buckets by `toStartOfMonth(hit_created_at)`, i.e.
     * the whole calendar month, whereas the trait bounds the current month at
     * `now(tz)->endOfDay()`. Those windows differ by up to one UTC offset's worth of the most
     * recent hits. Since this card renders BEFORE staging-live-hits and its write wins, using
     * the batch here would silently change that card's published "This Month" number for
     * every non-UTC org -- and only for entitled orgs, since a locked card computes nothing.
     * Same query, same bounds, same TTL arm as the trait: the two cards agree by construction.
     *
     * A null result means the fetch FAILED: write nothing at all, including the key that acts
     * as this method's own resolved-guard, so the next render retries rather than persisting a
     * fabricated 0.
     */
    private function resolveSharedCurrentMonth(int $orgId, string $timezone): void
    {
        $guardKey = self::STAGING_LIVE_PREFIX.':'.self::UNIQUE_NAMESPACE.'this_month:org='.$orgId;

        if (cache()->get($guardKey) !== null) {
            return;
        }

        $timezone = $this->sanitizedViewerTimezone($timezone);
        $start = now(tz: $timezone)->startOfMonth()->startOfDay()->toDateTimeString();
        $end = now(tz: $timezone)->endOfDay()->toDateTimeString();

        // RANGE-scoped, not the past-months batch scope -- see sharedRangeFailureMarkerKey().
        $markerKey = $this->sharedRangeFailureMarkerKey($orgId, $start, $end);

        if (cache()->get($markerKey) !== null) {
            return;
        }

        try {
            $rangeResult = $this->fetchSharedCurrentMonth($orgId, $start, $end);
        } catch (\Throwable $e) {
            if (\in_array(config('app.env'), ['production', 'testing'])) {
                report($e);

                cache()->put($markerKey, true, self::SHARED_FETCH_FAILURE_TTL_SECONDS);

                return;
            }

            throw $e;
        }

        $currentTTL = $this->baseDashboardCacheTTL(0);

        foreach (self::SHARED_PREFIX_MAP as $prefix => $col) {
            cache()->put(
                $prefix.':'.self::UNIQUE_NAMESPACE.'this_month:org='.$orgId,
                $rangeResult[$col] ?? 0,
                $currentTTL
            );
        }
    }

    /**
     * Overrides BuildsDateFilterChartSeries::chartSeries(): the sparkline is the
     * deduped Staging Live series `SharedHitStatsData` already maintains, never the sum
     * of this card's own top-10-per-month leaderboard buckets.
     *
     * @return array<int, float>
     */
    protected function chartSeries(): array
    {
        $orgId = auth()->user()->org_id;
        $timezone = $this->sanitizedViewerTimezone();

        $this->resolveSharedMonths($orgId, $timezone);
        $this->resolveSharedCurrentMonth($orgId, $timezone);

        $points = [];

        for ($i = 12; $i >= 0; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');
            $dateType = $i === 0 ? 'this_month' : $monthKey;

            $points[] = (float) (cache()->get(self::STAGING_LIVE_PREFIX.':'.self::UNIQUE_NAMESPACE.$dateType.':org='.$orgId) ?? 0);
        }

        return $points;
    }

    public function getGraphLabels(): array
    {
        $labels = [];

        $timezone = $this->sanitizedViewerTimezone();

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $labels[] = $targetMonth->format('M \'y');
        }

        return $labels;
    }

    protected function makeDataset(string $label, array $data, ?string $backgroundColor = null, ?string $stack = null): array
    {
        return \array_merge([
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $backgroundColor ?? 'rgba(156, 163, 175, 0.3)',
            'borderColor' => $backgroundColor ?? 'rgba(156, 163, 175, 0.5)',
            'hoverBackgroundColor' => $backgroundColor ?? 'rgba(156, 163, 175, 0.6)',
            'hoverBorderColor' => 'rgba(156, 163, 175, 0.8)',
            'borderWidth' => 1,
            'hoverBorderWidth' => 2,
        ], $stack ? ['stack' => $stack] : []);
    }

    public function getCardQueryStatements(): array
    {
        $orgId = (int) auth()->user()->org_id;
        [$start, $end, $timezone] = $this->resolveRangeAndTimezone();

        return [new CardQueryStatement(
            label: 'Headline total (top kit)',
            sql: StagingLiveHitLeaderQuery::handleRangeSql([
                'org_id' => $orgId,
                'start' => $start,
                'end' => $end,
                'timezone' => $timezone,
            ]),
            note: 'GROUP BY kit_id WITH ROLLUP. Key 0 is the org total and is not ranked. The card reads the highest kit total.',
            queryClass: StagingLiveHitLeaderQuery::class,
        )];
    }
}
