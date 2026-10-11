<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\BuildsDateFilterChartSeries;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\RendersDashboardStat;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Per-kit distinct-plate leaderboard for the selected window (DEV-2468), riding
 * ScanPlateStatsBatchQuery::handleRange()'s `WITH ROLLUP` scan DEV-2467 already ships.
 *
 * Deliberately NOT `use DashboardWidget` -- it models a single scalar per month, which
 * cannot represent a ranked list of kits.
 *
 * Q1 Option A: the sparkline is the ORG-TOTAL monthly series UniqueScanCounts already
 * maintains, read from the shared class-neutral month key -- NEVER a per-kit x month
 * chart. A plate seen by two kits counts in both, so per-kit values do not sum to the
 * org total; a stacked 13-month chart would visually overstate it, and the combined
 * `GROUP BY month, kit_id` shape has never been measured against `scans_cold`.
 */
class UniqueScansLeaderboard extends ChartWidget implements DashboardMetricCard, ExplainsCardQuery
{
    use BuildsDateFilterChartSeries;
    use RendersDashboardStat;
    use StatHelper;

    /**
     * Leader bars keep the card's `success` green; the rest use Filament's neutral grey,
     * which is `Color::Zinc` (Filament maps `gray => Color::Zinc`). Zinc is published in
     * oklch, and these are its 500/400 steps copied verbatim rather than re-derived, so the
     * chart cannot drift from the framework palette.
     */
    private const LEADER_FILL = 'rgba(34, 197, 94, 0.6)';

    private const LEADER_BORDER = 'rgba(34, 197, 94, 1)';

    private const FIELD_FILL = 'oklch(0.552 0.016 285.938)';

    private const FIELD_BORDER = 'oklch(0.705 0.015 286.067)';

    protected ?string $heading = 'Scans Leaderboard';

    protected function getTooltipDescription(): ?string
    {
        return 'Your top kit by distinct plates scanned in this period. Click to see the full ranking.';
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

    protected function getOptions(): RawJs
    {
        // The chart is a SINGLE-SERIES ranking (one bar per kit, kit names on the x axis),
        // unlike RecoveryLeader and StagingLiveHitLeader, which plot kits x MONTHS and rely
        // on a legend to name each series. Their options were the starting point here, so
        // two of their choices had to be undone: `interaction.mode: 'index'` is a
        // multi-series idiom, and with the legend off nothing on the canvas stated what the
        // bars measure -- the unit appeared only in the hover tooltip (DEV-2468 QA).
        //
        // The subtitle states WHAT the bars are and deliberately omits WHICH WINDOW. These
        // options are evaluated once at mount and painted onto <canvas>; Livewire re-renders
        // the Blade description on a date-filter change but never re-applies them, so a
        // window named here goes stale and the card contradicts itself -- an earlier revision
        // of this fix shipped '... - This Month' in the subtitle and QA caught it reading
        // 'This Month' while the description below said 'Last Month'. The window belongs
        // only in {@see getDescription()}, which does re-render.
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    legend: {
                        display: false,
                    },
                    subtitle: {
                        display: true,
                        text: 'Top 10 kits by distinct plates',
                        padding: { bottom: 12 },
                        font: { size: 12, style: 'normal' },
                        color: 'rgba(107, 114, 128, 1)',
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderWidth: 1,
                        cornerRadius: 6,
                        padding: 8,
                        displayColors: false,
                        position: 'nearest',
                        yAlign: 'top',
                        xAlign: 'center',
                    },
                },
                interaction: {
                    mode: 'nearest',
                    intersect: true,
                },
                animation: {
                    duration: 750,
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Distinct plates',
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                        },
                        ticks: {
                            maxTicksLimit: 8,
                        },
                    },
                    x: {
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
        return 'unique-scans-leaderboard';
    }

    /**
     * Headline = the leader's own count for the page date filter, never a sum of the
     * ranking.
     *
     * The `Top kit` description is REQUIRED, not decoration. The ticket's "definition
     * wrinkle" clause states that per-kit distinct plates do not sum to the org's distinct
     * plates and that "the card must be unambiguous about which it shows" -- and the compact
     * card is otherwise a bare number above a sparkline of the ORG TOTAL, two different
     * measures with nothing distinguishing them (on the QA fixture, 16 sitting on a line
     * ending at 84). RecoveryLeader and StagingLiveHitLeader now carry the same label, but for
     * a different reason -- DEV-2586 asked for the leader to be identifiable. They never needed
     * it for disambiguation: a recovery or a hit belongs to exactly one kit, so their per-kit
     * values DO sum to their org total. Only plate-uniqueness double-counts, so this is still
     * the only card that HAS to say which number it is showing.
     *
     * The description names the LEADING KIT rather than the window. The window is already
     * on the page's own date filter, whereas the kit behind the number was nowhere on the
     * compact card -- and naming it says "this is one kit" far more directly than any label
     * about the period could. It also removes a staleness surface: there is no longer a
     * second copy of the window text on this card that could drift out of step with the
     * filter, which is exactly how the chart subtitle went wrong (see {@see getOptions()}).
     *
     * Sorted rather than `max()`-ed because the NAME is needed, not just the number; the
     * displayed figure is that same leader row's total, so the number and the name can never
     * describe different kits.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        $leader = collect($this->leaderboardForSelectedRange())->sortByDesc('total')->first();

        return $this->dashboardStat(
            Stat::make($this->getCardLabel(), \number_format($leader['total'] ?? 0))
                ->description($this->leaderKitDescription($leader['name'] ?? null))
                ->chart($this->chartSeries())
                ->color('success'),
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

        $totals = collect($leaderboard)->pluck('total')->all();

        return [
            'datasets' => [
                $this->makeDataset('Distinct Plates', $totals),
            ],
            'labels' => collect($leaderboard)->pluck('name')->all(),
        ];
    }

    public function getDescription(): string|Htmlable|null
    {
        $leaderboard = collect($this->leaderboardForSelectedRange())->sortByDesc('total')->values();

        $leader = $leaderboard->first();
        $runnerUp = $leaderboard->get(1);

        $label = $this->dateLabel();

        // "with 16" left the reader to guess the unit; the word "plates" appeared nowhere
        // on the card except the hover tooltip (DEV-2468 QA). The `Leader for {label}:`
        // prefix is a house convention shared with RecoveryLeader and StagingLiveHitLeader
        // and is asserted on by tests/e2e/staging-live-hit-leader.spec.ts -- only the tail
        // after the number changes.
        return new HtmlString(
            "Leader for {$label}: <b>".e($leader['name'] ?? 'N/A').'</b> with '.\number_format($leader['total'] ?? 0).' distinct plates'.
            "<br/>Runner-up for {$label}: <b>".e($runnerUp['name'] ?? 'N/A').'</b> with '.\number_format($runnerUp['total'] ?? 0).' distinct plates'
        );
    }

    /**
     * The selected window's display label, for {@see getDescription()} only.
     *
     * Deliberately NOT used by {@see getOptions()}: canvas-painted chart options are not
     * re-applied on a Livewire date-filter change, so any window label rendered there
     * goes stale. See the comment in getOptions().
     */
    private function dateLabel(): string
    {
        return match ($this->date) {
            'today' => 'Today',
            'last_7d' => 'Last 7 Days',
            'last_month' => 'Last Month',
            default => 'This Month',
        };
    }

    /**
     * Top-10 per-kit ranking for the page date filter, ALWAYS the shared window rollup --
     * unlike StagingLiveHitLeader, there is no per-kit x month batch to fall back to for
     * this_month (Q1 rejected that shape), so every date arm resolves through
     * fetchRangeRollup() and {@see ScanPlateStatsBatchQuery::sharedRangeCacheKey()}. The
     * `kit_id = 0` rollup row is the org total, never a ranked entry -- dropped before
     * sorting, never merely sorted last.
     *
     * `$names[$kitId]` is always present here -- `handleRange()`'s SQL guards the blank
     * `kit_name String DEFAULT ''` case itself, so no PHP-side fallback is needed or
     * reachable (DEV-2468 S-G).
     *
     * @return array<int, array{id: int, name: string, total: int}>
     */
    public function leaderboardForSelectedRange(): array
    {
        $orgId = auth()->user()->org_id;

        [$start, $end, $timezone] = $this->resolveRangeAndTimezone();

        $rollup = $this->fetchRangeRollup($orgId, $start, $end, $timezone);

        $totals = $rollup['totals'];
        $names = $rollup['names'];

        unset($totals[0]);

        $leaderboard = [];
        foreach ($totals as $kitId => $total) {
            $leaderboard[] = [
                'id' => $kitId,
                'name' => $names[$kitId],
                'total' => $total,
            ];
        }

        \usort($leaderboard, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: $a['id'] <=> $b['id']);

        return \array_slice($leaderboard, 0, 10);
    }

    /**
     * The `(start, end, timezone)` triple for `$this->date`, chosen to match
     * UniqueScanCounts's per-arm zone convention EXACTLY so `sharedRangeCacheKey()`
     * resolves to the SAME string on both cards for the arms that share (DEV-2468 S-A):
     *
     *   - `today` / `last_7d`: {@see BuildsDateFilterChartSeries::viewerTimezone()}, the
     *     same sanitised zone `resolveCardRange()` and `UniqueScanCounts::getRangeTotal()`
     *     pass into `toUtcDatetime()`. A raw `NotAReal/Zone` throws inside that parse.
     *     These are the two arms `DashboardWidget::getCardTotal()` also routes through
     *     `getRangeTotal()`.
     *   - `this_month` / `last_month`: the SANITISED bucket zone, via the shared
     *     {@see ScanPlateStatsBatchQuery::currentMonthWindow()} /
     *     {@see ScanPlateStatsBatchQuery::lastMonthWindow()} formulas. BOTH
     *     month-granularity arms belong here, because `getCardTotal()` routes both of them
     *     to the bucket-zone 13-month series -- so the month UniqueScanCounts DISPLAYS is a
     *     bucket-zone month, and this card has to name the same one.
     *
     * Getting this split wrong is not theoretical. Using the raw zone for `this_month`
     * silently defeated the sharing entirely for any zone `safeTimezone()` does not
     * recognise; fixing only that arm then left `last_month` raw, so for a `+05:00` viewer
     * the two cards could report DIFFERENT MONTHS side by side on the same row.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function resolveRangeAndTimezone(): array
    {
        if (\in_array($this->date, ['this_month', 'last_month'], true)) {
            $bucketZone = ScanPlateStatsBatchQuery::safeTimezone(auth()->user()->timezone ?? 'UTC');

            $window = $this->date === 'last_month'
                ? ScanPlateStatsBatchQuery::lastMonthWindow($bucketZone)
                : ScanPlateStatsBatchQuery::currentMonthWindow($bucketZone);

            return [$window['start'], $window['end'], $bucketZone];
        }

        $zone = $this->viewerTimezone();
        [$start, $end] = $this->resolveCardRange();

        return [$start->toDateTimeString(), $end->toDateTimeString(), $zone];
    }

    /**
     * A single window's `handleRange()` rollup, behind its own overridable seam so a test
     * can COUNT invocations -- `DB::listen` never fires for the ClickHouse driver. Reads
     * and writes the SAME {@see ScanPlateStatsBatchQuery::sharedRangeCacheKey()}
     * UniqueScanCounts does -- but only for the arms UniqueScanCounts actually warms
     * (DEV-2468 S-B; the earlier docblock here overclaimed "always"):
     *
     *   - `today` / `last_7d`: shares `UniqueScanCounts::getRangeTotal()`'s key.
     *     Whichever of the two cards renders first computes the scan; the other's
     *     `cache()->remember()` is then a hit.
     *   - `this_month`: shares `UniqueScanCounts::resolveCurrentMonthFromCold()`'s key,
     *     via the identical `resolveRangeAndTimezone()` construction above.
     *   - `last_month`: NOT shared. `UniqueScanCounts` reads Last Month straight off its
     *     monthly bucket series (a single org-total number) and never computes a per-kit
     *     range rollup for it, so nobody else warms this key. That is not a sharing bug
     *     to engineer around -- no other card needs a per-kit ranking for last month, so
     *     this is the one arm where this card genuinely issues its own scan: one bounded
     *     `GROUP BY kit_id WITH ROLLUP` read per org per the ladder's open arm
     *     (10 min, 10s under `admin_impersonator`).
     *
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        return ScanPlateStatsBatchQuery::rememberedRange($orgId, $start, $end, $timezone);
    }

    /**
     * Overrides BuildsDateFilterChartSeries::chartSeries(): the sparkline is the ORG-TOTAL
     * monthly series UniqueScanCounts already maintains, read from the DEV-2468 shared
     * month key -- NEVER the inherited default, which `array_sum()`s each bucket. This
     * card's own buckets are per-kit ranking rows, and per-kit distinct-plate counts do
     * not sum to the org total (a plate seen by two kits counts in both), so the default
     * would silently overstate the sparkline.
     *
     * A PASSIVE read only -- this card never fills the shared month keys itself.
     * UniqueScanCounts (grid slot 2) renders before this card (slot 14) and does the
     * filling; an uncached bucket here reads as 0 rather than issuing a second scan.
     * That precedence is load-bearing -- keep UniqueScanCounts ahead of this card in
     * CompanyMetricsOverview::cards().
     *
     * @return array<int, float>
     */
    protected function chartSeries(): array
    {
        $orgId = auth()->user()->org_id;
        $bucketZone = ScanPlateStatsBatchQuery::safeTimezone(auth()->user()->timezone ?? 'UTC');

        $points = [];

        for ($i = 12; $i >= 0; $i--) {
            $dateType = now(tz: $bucketZone)->startOfMonth()->subMonths($i)->format('Y-m');

            $points[] = (float) (ScanPlateStatsBatchQuery::cachedMonthInt($orgId, $dateType, $bucketZone) ?? 0);
        }

        return $points;
    }

    /**
     * @param  array<int, int>  $data
     * @return array<string, mixed>
     */
    /**
     * The leader's bar keeps the card's `success` green; the rest are Filament's neutral grey.
     *
     * The chart is the card's own headline restated: `toStat()` shows the LEADER's count and
     * `getDescription()` names the leader and runner-up, yet every bar rendered identically,
     * so the chart did not agree with the text above it. Sorting alone already puts the
     * leader leftmost, so this is emphasis, not discovery.
     *
     * Grey is Filament's own `gray`, which resolves to `Color::Zinc`
     * (`vendor/filament/support/src/Colors/ColorManager.php`) -- the palette token, not the
     * green faded down, so the field reads as a genuinely neutral colour rather than a washed
     * out version of the leader.
     *
     * A grey/green split was initially avoided here on the grounds that grey is the
     * dashboard's LOCKED signal, so a grey bar might read as "withheld". That objection does
     * not hold: {@see RendersDashboardStat::lockedStat()} never calls `->chart()`, so a locked
     * card renders NO bars at all. Grey bars therefore carry no locked-state meaning, and the
     * gated rendering of this card stays unambiguous.
     *
     * Every bar tied for the top is emphasised, not just the first -- with a tie, singling out
     * whichever kit sorted first would assert a ranking the data does not support.
     *
     * @param  array<int, float|int|null>  $data
     * @return array<string, mixed>
     */
    protected function makeDataset(string $label, array $data): array
    {
        $max = collect($data)->filter(fn ($v) => $v !== null)->max();

        $isLeader = static fn ($value): bool => $max !== null && $value !== null && $value >= $max;

        return [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => collect($data)
                ->map(fn ($v) => $isLeader($v) ? self::LEADER_FILL : self::FIELD_FILL)
                ->all(),
            'borderColor' => collect($data)
                ->map(fn ($v) => $isLeader($v) ? self::LEADER_BORDER : self::FIELD_BORDER)
                ->all(),
            'borderWidth' => 1,
            'hoverBorderWidth' => 2,
        ];
    }

    public function getCardQueryStatements(): array
    {
        [$start, $end, $timezone] = $this->resolveRangeAndTimezone();

        return [new CardQueryStatement(
            label: 'Headline total (top kit)',
            sql: ScanPlateStatsBatchQuery::handleRangeSql([
                'org_id' => auth()->user()->org_id,
                'start' => $start,
                'end' => $end,
                'timezone' => $timezone,
            ]),
            note: 'Rolled up per kit -- the card drops the `is_rollup = 1` row (the org total) and '
                .'reads the highest remaining `unique_plates`.',
            queryClass: ScanPlateStatsBatchQuery::class,
        )];
    }
}
