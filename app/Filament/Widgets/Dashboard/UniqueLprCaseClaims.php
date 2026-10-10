<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\UniqueLprCaseClaimsQuery;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\HeldFlexibleCache;
use App\Filament\Widgets\Dashboard\Concerns\RatioAgainstUniqueScans;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Support\RawJs;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

/**
 * DEV-2463. The `deduped` funnel stage from UniqueLprCaseClaimsQuery -- billed claims,
 * not raw or successful-only counts.
 *
 * Two overrides diverge deliberately from the base DashboardWidget contract:
 *
 * - getMonthlyData() buckets on UTC calendar months, not auth()->user()->timezone as
 *   every sibling card does. UniqueLprCaseClaimsQuery::monthlySeries() already groups
 *   server-side in UTC (this card's AC reproduces a UTC-calendar-month figure), so
 *   re-deriving the boundary from the viewer's timezone would shift a Chicago viewer's
 *   month by 5-6 hours and silently break that reproduction.
 * - getMonthlyData() and getModelQueryForDates() each issue exactly ONE ClickHouse round
 *   trip (Cache::flexible([3600, 86400]) around monthlySeries(), and a single window()
 *   call for today/last_7d), instead of the base's up-to-13-query per-month fan-out.
 *
 * TWO CALENDARS, DELIBERATELY. The month axis is UTC (the AC reproduces a UTC-calendar-month
 * figure); `today`/`last_7d` stay on the VIEWER's local day, inherited from
 * resolveCardRange(). So the two are not directly comparable: at 20:00 America/Chicago on
 * Aug 31 (= 01:00 UTC Sep 1) "Today" counts the local Aug-31 day, whose claims sit in UTC
 * August, while "This Month" is UTC September and near zero -- toggling the filter can make
 * the headline FALL.
 *
 * Both alternatives are worse. Local months break the AC outright. UTC days would mean that
 * same viewer's "Today" is a UTC day ~4 hours in the future, so a daily metric reads ~0 every
 * evening. Operator decision 2026-09-08: keep the mix and document it here. Any sibling
 * ticket reusing this query (DEV-2464, DEV-2465) inherits the same seam.
 *
 * RATIO NUMERATOR (DEV-2652). Unique scans are viewer-local via numeratorZone()
 * (ScanPlateStatsBatchQuery::safeTimezone()). Deduped claims stay UTC because AC5 is
 * deferred, so historical figures remain on Brek's Repo Report clock. The positional
 * zip is two clocks and disagrees near a boundary.
 *
 * getStatColor() returns 'success', paired with UniqueScanCounts' own success RGB (the
 * slot ScanCount held before DEV-2651 t8 deleted it). An earlier
 * pass used 'purple', which is NOT registered in PlatformPanelProvider::colors() -- it
 * renders untinted with no error. Registering a new shade would touch that trait, which
 * both the app and client panels share, so this reuses a registered colour instead;
 * neither immediate neighbour in the grid (recovery-counts 'info', staging-live-hit-leader
 * 'warning') collides with it. The widget test asserts the colour actually resolves.
 */
class UniqueLprCaseClaims extends DashboardWidget implements ExplainsCardQuery
{
    use HeldFlexibleCache;
    use RatioAgainstUniqueScans;

    /** Headline clock. The numerator reads numeratorZone(); it must not read this. */
    private const SERIES_ZONE = 'UTC';

    /**
     * getCacheTTL() collapses to 10s under admin_impersonator -- shorter than this query's
     * measured contended p99 (9.3s), so a re-render could start the next ~40M-row scan
     * before the previous returned. This floors it.
     */
    private const MIN_TTL_SECONDS = 60;

    /**
     * Cold-path herd guard. Cache::flexible locks only its stale-refresh branch
     * (Repository::flexible() line ~642); a TOTAL miss is a bare
     * `tap(value($callback), ...)` with no lock, so a deploy or cache:clear admits one
     * ~40M-row scan per entitled org AND per concurrent tab.
     *
     * COLD_BLOCK_SECONDS must exceed the query's measured contended p99 (9.3s) or every
     * waiter times out and computes anyway -- which is how the first attempt at this guard
     * made things worse rather than better. COLD_LOCK_SECONDS must exceed
     * CLICKHOUSE_TIMEOUT_QUERY (60s) or a slow holder loses the mutex mid-query and a
     * waiter starts a second copy. REFRESH_LOCK_SECONDS is handed to Cache::flexible so its
     * OWN refresh lock expires: it defaults to `$lock['seconds'] ?? 0` (no expiry), so a
     * worker killed mid-refresh would otherwise wedge the key until the 24h hard TTL.
     */
    private const COLD_BLOCK_SECONDS = 12;

    private const COLD_LOCK_SECONDS = 90;

    public const REFRESH_LOCK_SECONDS = 90;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'LPR Case Claims';

    /** @var array<int, float>|null DEV-2651 t3. Zipped from getMonthlyData()'s own array -- see getRatioMonthlyData(). */
    private ?array $ratioMonthlyDataMemo = null;

    protected function getTooltipDescription(): ?string
    {
        return 'Billed LPR case claims in this period, counted once per vehicle.';
    }

    public function getDatasetLabel(): string
    {
        return 'LPR Case Claims';
    }

    public function getRgbColor(): array
    {
        return [34, 197, 94];
    }

    /**
     * DEV-2651 t3. This card's own memos (the ratio series) and the trait's numerator flag
     * survive a plain DashboardWidget::refreshData() -- it only clears $monthlyDataMemo.
     * Left uncleared, a filter switch on the expanded card could show a stale ratio series
     * or keep rendering UNAVAILABLE_VALUE after a since-recovered numerator.
     *
     * ==> THE #[On] ATTRIBUTE MUST BE RE-DECLARED HERE. <== PHP does not inherit method
     * attributes onto an override, so the parent's #[On('refresh-dashboard')] is invisible
     * to Livewire without redeclaring it (mirrors RecoveryCounts::refreshData()).
     */
    #[On('refresh-dashboard')]
    public function refreshData(string $date): void
    {
        parent::refreshData($date);

        $this->ratioMonthlyDataMemo = null;
        $this->numeratorUnavailable = false;
    }

    /**
     * AC-2: the headline stays the deduped claims total; this adds the ratio as a secondary
     * Stat::description() line. Decision 8: a numerator failure renders UNAVAILABLE_VALUE
     * in that line ALONE -- the headline itself is unaffected, since it never depended on
     * the numerator.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(
            Stat::make($this->getCardLabel(), \number_format($this->getCardTotal()))
                ->description($this->getRatioDescription())
                ->chart($this->chartSeries())
                ->color($this->getStatColor()),
            $selectedCard,
        );
    }

    /**
     * AC-3/4: the expanded chart gains a second dataset -- the ratio, on its own right-hand
     * axis (getOptions()'s scales.y1) so its much smaller scale doesn't flatten the claims
     * bars. Decision 8: a numerator failure drops this dataset entirely rather than plot a
     * fabricated flat line; getRatioMonthlyData() is what sets that flag, so it is computed
     * unconditionally before the check.
     *
     * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
     */
    public function getData(): array
    {
        $claims = $this->getMonthlyData(formatted: false);
        $ratios = $this->getRatioMonthlyData();

        if ($this->numeratorUnavailable) {
            // Single series, no legend: makeDataset()'s per-month highlight is still the
            // right call here, and only here.
            return [
                'datasets' => [\array_merge($this->makeDataset($this->getDatasetLabel(), $claims), ['yAxisID' => 'y'])],
                'labels' => $this->getGraphLabels(),
            ];
        }

        $datasets = [
            $this->countSeriesDataset($this->getDatasetLabel(), $claims),
            $this->ratioLineDataset('Scans per Case Claim', $ratios),
        ];

        return [
            'datasets' => $datasets,
            'labels' => $this->getGraphLabels(),
        ];
    }

    /**
     * AC-3: a legend distinguishing the two series, shown only on the expanded card (mirrors
     * SharedHitStatsData::getOptions()). AC-4: scales.y1 is a second, right-hand axis for the
     * ratio dataset -- grid.drawOnChartArea is false so its gridlines don't double up with
     * the left axis, and both axes carry a title since they now plot different units.
     *
     * @return array<string, mixed>|RawJs|null
     */
    protected function getOptions(): array|RawJs|null
    {
        $options = $this->options;

        // Review-4 R4-B: getData() drops the ratio dataset when the numerator is unavailable,
        // so the axis and the legend must drop with it -- otherwise a degraded card renders a
        // titled right-hand axis with nothing plotted against it and a one-entry legend.
        // getRatioMonthlyData() is what SETS the flag, so it has to run before the check;
        // it is memoised, so this costs nothing when getData() already ran. Mirrors
        // RecoveryCounts::getOptions(), which has always done this.
        // Gate BEFORE the fetch, like RecoveryCounts::ratioSeriesForChart(): getRatioMonthlyData()
        // dereferences auth()->user(), and canViewRatioFigure() starts with auth()->check().
        $ratioAvailable = static::canViewRatioFigure();

        if ($ratioAvailable) {
            $this->getRatioMonthlyData();
            $ratioAvailable = ! $this->numeratorUnavailable;
        }

        $options['plugins']['legend']['display'] = $ratioAvailable && $this->expanded;
        $options['plugins']['legend']['position'] = 'top';
        $options['plugins']['legend']['labels']['usePointStyle'] = true;

        $options['scales']['y']['title'] = [
            'display' => true,
            'text' => 'Case Claims',
        ];

        if (! $ratioAvailable) {
            return $options;
        }

        $options['scales']['y1'] = [
            'beginAtZero' => true,
            'position' => 'right',
            'grid' => [
                'drawOnChartArea' => false,
            ],
            'title' => [
                'display' => true,
                'text' => 'Scans per Case Claim',
            ],
        ];

        return $options;
    }

    protected function coldBlockSeconds(): int
    {
        return self::COLD_BLOCK_SECONDS;
    }

    protected function coldLockSeconds(): int
    {
        return self::COLD_LOCK_SECONDS;
    }

    protected function refreshLockSeconds(): int
    {
        return self::REFRESH_LOCK_SECONDS;
    }

    /**
     * ONE Cache::flexible([3600, 86400]) call around the 13-month UTC series, replacing
     * the base's up-to-13-query per-month fan-out. Preserves the $monthlyDataMemo and
     * formatMonthlyData() contracts so getCardTotal(), chartSeries() and getDescription()
     * keep working untouched. A month with no rows is 0, never missing.
     *
     * @return array<int, int|float>
     */
    protected function getMonthlyData(bool $formatted = true): array
    {
        $memoKey = $formatted ? 'formatted' : 'raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $orgId = auth()->user()->org_id;
        $through = now(self::SERIES_ZONE);

        // The key MUST carry the window's identity. Keyed only on the org, a series
        // computed at 23:55 on the last day of a UTC month is still "fresh" minutes into
        // the next one, the new month's bucket is absent, and the ?? 0 below fabricates a
        // zero -- indistinguishable from a legitimate zero, which this card treats as a
        // normal reading. The base class avoids this by keying each month separately;
        // this override keeps the single batched query and puts the month in the key.
        $series = $this->heldFlexible(
            $this->buildWidgetCacheKey('monthly-series:'.$through->format('Y-m'), $orgId),
            [3600, 86400],
            fn () => $this->fetchMonthlySeries($orgId, $through)
        );

        $data = [];

        for ($i = 12; $i >= 0; $i--) {
            $bucketKey = $through->clone()->startOfMonth()->subMonths($i)->format('Y-m-01');
            $data[] = $series[$bucketKey]['deduped'] ?? 0;
        }

        if ($formatted && \method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * The label axis MUST co-vary with the data axis. getMonthlyData() buckets in UTC, but
     * the base builds labels, the highlighted month and the description from
     * auth()->user()->timezone -- so for a Chicago viewer between 00:00 and 05:00/06:00 UTC
     * on the 1st, every bar would be labelled one month earlier than the data it holds and
     * "Current Month" would show the new UTC month's near-zero figure. These three
     * overrides move the label axis to UTC too.
     *
     * @return array<int, string>
     */
    public function getGraphLabels(): array
    {
        $labels = [];

        for ($i = 12; $i >= 0; $i--) {
            $labels[] = now('UTC')->startOfMonth()->subMonthsNoOverflow($i)->format('M \'y');
        }

        return $labels;
    }

    /**
     * @return array<int, string>
     */
    protected function getHighlightedMonthsFromFilter(): array
    {
        return match ($this->date) {
            'last_month' => [now('UTC')->startOfMonth()->subMonthNoOverflow()->format('M \'y')],
            default => [now('UTC')->format('M \'y')],
        };
    }

    /**
     * Same as the base, except the month names are UTC to match the buckets.
     */
    public function getDescription(): string|Htmlable|null
    {
        if ($this->date === 'today') {
            return new HtmlString('Today: '.\number_format($this->currentPeriodTotal()));
        }

        if ($this->date === 'last_7d') {
            return new HtmlString('Last 7 Days: '.\number_format($this->currentPeriodTotal()));
        }

        $monthlyData = $this->getMonthlyData();
        $current = now('UTC')->format('M \'y');
        $previous = now('UTC')->startOfMonth()->subMonthNoOverflow()->format('M \'y');

        return new HtmlString(
            $current.': '.\number_format($monthlyData[\count($monthlyData) - 1] ?? 0)
            .' | '.$previous.': '.\number_format($monthlyData[\count($monthlyData) - 2] ?? 0)
        );
    }

    /**
     * Floor the base TTL. getCacheTTL() collapses to 10s under admin_impersonator, which is
     * shorter than this query's contended p99 (9.3s) -- so the window seam could re-issue a
     * ~40M-row scan before the previous one returned.
     *
     * CORRECTED CLAIM (review round 3, lane 1). An earlier version of this comment said
     * impersonators "still get the freshest value any sibling card offers within a minute".
     * That overstated it. The honest position: most siblings route through the base
     * getCacheTTL() and so DO collapse to 10s while impersonating, which is 6x fresher than
     * this floor -- okf/concepts/impersonation.md records that collapse as deliberate, so
     * this IS a divergence and needs an okf note post-merge. It is not unprecedented
     * (MissedOpportunityClientGraph:69 hardcodes 15 minutes with no collapse at all), and
     * the justification is the measurement: a 10s TTL on a query whose contended p99 is 9.3s
     * cannot be honoured. Note also that this floor only reaches the today/last_7d seam --
     * the monthly seam's [3600, 86400] bypasses getCacheTTL() entirely and is staler still.
     */
    protected function getCacheTTL($index = 0, ?\DateTimeInterface $monthEndedAt = null): \DateTimeInterface
    {
        $ttl = parent::getCacheTTL($index, $monthEndedAt);
        $floor = now()->addSeconds(self::MIN_TTL_SECONDS);

        // getTimestamp(), not Carbon's lessThan(): the parent's declared return type is
        // \DateTimeInterface, and lessThan() is Carbon-only -- composer analyse flags it.
        return $ttl->getTimestamp() < $floor->getTimestamp() ? $floor : $ttl;
    }

    /**
     * The single seam getMonthlyData() resolves through -- overridden by a counting
     * subclass in the test suite to pin the one-round-trip AC.
     *
     * @return array<string, array{all_claims: int, successful: int, deduped: int}>
     */
    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return UniqueLprCaseClaimsQuery::monthlySeries($orgId, $through);
    }

    /**
     * currentPeriodTotal() (today/last_7d) routes through this, a DIFFERENT path from
     * getMonthlyData() -- its own single-window one-query implementation, so no filter
     * state can trigger more than one ClickHouse round trip. $startDate/$endDate arrive
     * as resolveCardRange()'s naive strings in the viewer's timezone (the base class's
     * own convention), so they are re-anchored to that timezone before conversion to UTC
     * -- never re-derived by hand (Carbon's ->utc()->timestamp only, inside the query).
     */
    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        $timezone = $this->viewerTimezone();

        return $this->fetchWindowTotal(
            auth()->user()->org_id,
            Carbon::parse($startDate, $timezone),
            Carbon::parse($endDate, $timezone),
        );
    }

    /**
     * The single seam getModelQueryForDates() resolves through -- overridden by a
     * counting subclass in the test suite to pin the one-round-trip AC.
     */
    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->runWindowQuery($orgId, $start, $end)['deduped'];
    }

    /**
     * The query call itself, split from the ['deduped'] stage selection above so the stage
     * choice is testable. Every test double used to override fetchWindowTotal() wholesale,
     * which is why a ['deduped'] -> ['successful'] mutant survived the whole suite
     * (review round 3, lane 1 M1).
     *
     * @return array{all_claims: int, successful: int, deduped: int}
     */
    protected function runWindowQuery(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        return UniqueLprCaseClaimsQuery::window($orgId, $start, $end);
    }

    /**
     * Decision 9 (critical). Zipped against getMonthlyData()'s OWN array -- NEVER a second,
     * independently-bucketed fetchMonthlySeries() call. Two arrays from two queries can
     * drift apart by a month; one array zipped against itself cannot. $claims below IS the
     * exact array the count dataset renders (getData() computes it the same way), and $scans
     * is the ONE new read this ticket adds (RatioAgainstUniqueScans' numerator).
     *
     * The zip is positional across two clocks. $through is the viewer zone; $claims is the
     * UTC headline array. Index i is "i months before now" on each clock, so the pair
     * disagrees near a zone boundary. Labels stay on the headline clock.
     *
     * @return array<int, float>
     */
    protected function getRatioMonthlyData(): array
    {
        if ($this->ratioMonthlyDataMemo !== null) {
            return $this->ratioMonthlyDataMemo;
        }

        $orgId = auth()->user()->org_id;
        $zone = $this->numeratorZone();
        $through = now(tz: $zone);

        $claims = $this->getMonthlyData(formatted: false);
        $scans = $this->fetchUniqueScansSeries($orgId, $through, $zone);

        $ratios = [];

        for ($i = 12; $i >= 0; $i--) {
            $month = $through->clone()->startOfMonth()->subMonths($i);
            $index = 12 - $i;

            $ratios[] = self::ratio($scans[$month->format('Y-m')] ?? 0, $claims[$index] ?? 0);
        }

        return $this->ratioMonthlyDataMemo = $ratios;
    }

    /**
     * The today/last_7d arm reuses getCardTotal() (the SAME cached deduped value the headline
     * already renders) as its denominator rather than re-deriving the raw stage array a
     * second time -- one more application of decision 9's "zip against the existing value"
     * rule, just on the window leg instead of the monthly one.
     *
     * Numerator bounds use resolveCardRange()'s day expressions, evaluated in numeratorZone()
     * after sanitising. Do not feed resolveCardRange()'s raw-zone Carbon in: an unlisted id
     * can throw inside DateTimeZone, and safeTimezone() stays non-throwing via in_array.
     */
    private function getCurrentPeriodRatio(): float
    {
        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            $zone = $this->numeratorZone();
            [$start, $end] = match ($this->date) {
                'today' => [
                    now(tz: $zone)->startOfDay(),
                    now(tz: $zone)->endOfDay(),
                ],
                'last_7d' => [
                    now(tz: $zone)->subDays(7)->startOfDay(),
                    now(tz: $zone)->endOfDay(),
                ],
            };
            $orgId = auth()->user()->org_id;

            $numerator = $this->windowUniqueScans($orgId, $start, $end, $zone);

            return self::ratio($numerator, $this->getCardTotal());
        }

        $ratios = $this->getRatioMonthlyData();

        return (float) ($this->date === 'last_month'
            ? ($ratios[\count($ratios) - 2] ?? 0.0)
            : ($ratios[\count($ratios) - 1] ?? 0.0));
    }

    /**
     * AC-2's Stat::description() line. Decision 8: UNAVAILABLE_VALUE replaces only the
     * number -- getCurrentPeriodRatio() is what sets $numeratorUnavailable, so it must run
     * before this checks the flag.
     */
    private function getRatioDescription(): string
    {
        $ratio = $this->getCurrentPeriodRatio();

        $value = $this->numeratorUnavailable ? self::UNAVAILABLE_VALUE : \number_format($ratio, 1);

        return $value.' scans per case claim';
    }

    public function getCardQueryStatements(): array
    {
        $orgId = auth()->user()->org_id;

        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            $timezone = $this->viewerTimezone();
            [$start, $end] = $this->resolveCardRange();

            return [new CardQueryStatement(
                label: 'Headline total',
                sql: UniqueLprCaseClaimsQuery::windowSql(
                    $orgId,
                    Carbon::parse($start->toDateTimeString(), $timezone),
                    Carbon::parse($end->toDateTimeString(), $timezone),
                ),
                note: 'The card reads the `deduped` column.',
                queryClass: UniqueLprCaseClaimsQuery::class,
            )];
        }

        $through = now('UTC');
        $offset = $this->date === 'last_month' ? 1 : 0;
        $bucketKey = $through->clone()->startOfMonth()->subMonths($offset)->format('Y-m-01');

        return [new CardQueryStatement(
            label: 'Headline total',
            sql: UniqueLprCaseClaimsQuery::monthlySeriesSql($orgId, $through),
            note: "Bucketed by month -- the card reads the `deduped` column of the `{$bucketKey}` row.",
            queryClass: UniqueLprCaseClaimsQuery::class,
        )];
    }
}
