<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\On;

/**
 * Filament StatsOverviewWidget owns the Company Metrics grid. Sibling
 * ChartWidget / TableWidget classes remain the query owners (getMonthlyData,
 * getDescription) so compact cards and the expanded detail share one path.
 * They are constructed here as data adapters -- not Livewire-mounted -- via toStat().
 *
 * Card clicks are handled by the Dashboard page (`$parent.toggleCard`). This
 * widget is #[Reactive] on pageFilters, so it re-queries only when the date
 * filter changes -- not when a card opens.
 *
 * Because cards are built as adapters, `mount()` never runs on them, so a card's own
 * `canView()` is not enforced on the compact path. That is what canViewCardData() /
 * lockedStat() exist for -- see getStats() and expandableCards() below (the Company Metrics foundation).
 *
 * NO CARD IN THIS GRID IS PRO-GATED. Every `canViewCardData()` override was removed and
 * `FeatureEnum::COMPANY_METRICS` deleted with them, so the seam now carries only the
 * ADMIN/USER role check `RendersDashboardStat::canViewCardData()` supplies by default.
 * canViewCardData()/lockedStat() are deliberately KEPT: the seam is still live (a role-less
 * viewer still renders locked cards) and MissedOpportunities still carries its upsell copy.
 *
 * LOAD, stated because it is the whole point of the seam: lockedStat() exists so the metric
 * is never COMPUTED for a viewer who may not see it (see DashboardMetricCard). With nothing
 * gated, every card's query -- including both leaderboards, MissedOpportunities and the
 * per-viewer-timezone ScanPlateStatsBatchQuery scan -- now runs for EVERY ADMIN/USER org on
 * first render of each month bucket, not just entitled ones. Past months cache for a year, so
 * this is a cold-start cost rather than a steady-state one, but it is a real widening.
 * getStats() catches a thrown toStat(), reports it, and renders that card unavailable
 * (`--` / Unavailable) so one ClickHouse failure cannot 500 the rest of the grid.
 */
class CompanyMetricsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    /**
     * An ARRAY, not the string 'full': Filament normalises a SCALAR span to `['lg' => $span]`,
     * which left this grid at half the page width below `lg` (measured 356px inside a 736px
     * row at a 768px viewport). `default` alone is enough -- `.fi-grid-col`'s base rule is not
     * media-queried. See the Company Metrics foundation layout findings.
     */
    protected int|string|array $columnSpan = [
        'default' => 'full',
    ];

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public ?string $date = 'this_month';

    /**
     * The compact cards in the ORDER THE OPERATOR SPECIFIED (2026-09-16, DEV-2651 registry
     * cutover). Keys match getCardKey() (class_basename snake-cased) and are also the toggle
     * key and the cache namespace, so a key is never renamed -- only its slot moves.
     *
     * Reading down the `lg` 4-column grid:
     *
     *   row 1 | Scan Counts          | LPR Case Claims          | LPR Repossessions        | Recovery Counts
     *   row 2 | Direct Live Hits     | Direct Historical Hits   | Staging Live Hits        | Staging Historical Hits
     *   row 3 | Missed Opportunities | Unique Scans Leaderboard | LPR Recovery Leaderboard | Staging Live Hit Leaderboard
     *
     * DEV-2651 absorbed Scan Count into Unique Scan Counts, Unique Scans to Case Claims into
     * Unique LPR Case Claims, Unique Scans to LPR Repossession into LPR Repossessions, and
     * Unique Scans to Repo into Recovery Counts -- 16 cards down to 12. The four absorbed
     * classes still exist (t8 deletes them); only their keys leave this array.
     *
     * THREE constraints hold, and all three are pinned by tests. Nothing else about this order
     * is derivable -- it is a stated preference, so do not "tidy" it toward any scheme you infer:
     *
     *   - the three per-kit leaderboards are the LAST THREE cards, in this order. Asked for
     *     directly, so it is asserted as "the final three" rather than as mere adjacency;
     *   - the four hit cards occupy four ADJACENT slots and relocate together, never reordered
     *     apart (`test_all_four_hit_cards_are_contiguous()`);
     *   - unique-scan-counts precedes unique-scans-leaderboard. That one is LOAD-BEARING:
     *     UniqueScansLeaderboard::chartSeries() is a passive read of the shared month keys
     *     UniqueScanCounts fills, so reversing them makes the leaderboard's sparkline read 0 on
     *     a cold cache. It is implied by the leaderboards-last rule today, but asserted
     *     separately because it must survive that rule changing.
     *
     * Visual row alignment is `count(preceding cards) % columns == 0`, NOT array adjacency.
     * Four cards precede the hit block, so at `lg` it currently occupies exactly one visual
     * row -- but it still splits at `default`/`md`, and inserting one card ahead of it de-aligns
     * it again. Do not read the present alignment as a target to preserve;
     * `test_all_four_hit_cards_are_contiguous()` measures ARRAY adjacency precisely so it keeps
     * holding at every card count.
     *
     * Kit Users is deliberately absent: it stopped being a card in the Company Metrics foundation
     * and is now a stat in HotlistCount's header row.
     *
     * @return array<string, class-string<DashboardMetricCard>>
     */
    public static function cards(): array
    {
        return [
            'unique-scan-counts' => UniqueScanCounts::class,
            'unique-lpr-case-claims' => UniqueLprCaseClaims::class,
            'lpr-repossessions' => LprRepossessions::class,
            'recovery-counts' => RecoveryCounts::class,
            'live-hits' => LiveHits::class,
            'historical-hits' => HistoricalHits::class,
            'staging-live-hits' => StagingLiveHits::class,
            'staging-historical-hits' => StagingHistoricalHits::class,
            'missed-opportunities' => MissedOpportunities::class,
            'unique-scans-leaderboard' => UniqueScansLeaderboard::class,
            'recovery-leader' => RecoveryLeader::class,
            'staging-live-hit-leader' => StagingLiveHitLeader::class,
        ];
    }

    /**
     * The subset of cards() whose data the viewing org may see, and therefore the only
     * cards that may be expanded. A locked card KEEPS its grid slot (cards() still lists
     * it) but is absent here, which is what makes it non-toggleable structurally rather
     * than by a permission check at expand time -- Dashboard::cardWidgets() resolves toggle
     * keys through this method (the Company Metrics foundation).
     *
     * canViewCardData() is static so this filters class-strings without instantiating.
     * `static::cards()` (not `self::`) so a subclass overriding cards() -- which is how the
     * seam is tested with stub cards -- is actually honoured.
     *
     * @return array<string, class-string<DashboardMetricCard>>
     */
    public static function expandableCards(): array
    {
        return \array_filter(
            static::cards(),
            static fn (string $class): bool => $class::canViewCardData(),
        );
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);

        $this->date = $this->resolveDashboardDate();
    }

    /**
     * Compact cards are adapters built inside getStats(), so they never receive
     * refresh-dashboard themselves. The page still dispatches that event when the
     * date toggle changes (same as the expanded ChartWidgets). Honour it here so
     * Last Month cannot keep rendering This Month totals from a stale cachedStats.
     */
    #[On('refresh-dashboard')]
    public function refreshDashboardDate(string $date): void
    {
        $this->date = $date;
        $this->cachedStats = null;
    }

    public function updatedPageFilters(): void
    {
        $this->date = $this->resolveDashboardDate();
        $this->cachedStats = null;
    }

    protected function getColumns(): int|array|null
    {
        return [
            'default' => 1,
            'md' => 2,
            'lg' => 4,
        ];
    }

    /**
     * Every card in cards() renders, in cards() order -- a locked card keeps its slot.
     *
     * The canViewCardData() branch is deliberately placed BEFORE toStat(). toStat() is what
     * runs the card's ClickHouse query, so gating here means a withheld metric is never
     * computed at all, rather than computed and then hidden at render time. Reversing these
     * two lines would turn the seam into a presentation-only gate and leak the value into
     * the response for any org that may not see it (the Company Metrics foundation).
     *
     * The try wraps only toStat(). A ClickHouse failure is reported and that one card
     * renders unavailable (`--` / Unavailable). lockedStat() stays outside the catch, and
     * the loop continues so the rest of the grid still renders.
     *
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        if (! \is_string($this->date) || $this->date === '') {
            $this->date = $this->resolveDashboardDate();
        }

        $stats = [];

        foreach (static::cards() as $class) {
            $widget = $this->makeMetricWidget($class);

            if (! $class::canViewCardData()) {
                $stats[] = $widget->lockedStat();

                continue;
            }

            try {
                $stats[] = $widget->toStat();
            } catch (\Throwable $exception) {
                report($exception);
                $stats[] = $this->unavailableStat($widget);
            }
        }

        return $stats;
    }

    private function unavailableStat(DashboardMetricCard $widget): Stat
    {
        $label = \method_exists($widget, 'getCardLabel')
            ? $widget->getCardLabel()
            : \class_basename($widget);

        return Stat::make($label, '--')
            ->description('Unavailable')
            ->color('gray');
    }

    /**
     * @param  class-string<DashboardMetricCard>  $class
     */
    protected function makeMetricWidget(string $class): DashboardMetricCard
    {
        $widget = new $class;

        if (\property_exists($widget, 'date')) {
            $widget->date = $this->date;
        }

        if (\property_exists($widget, 'filters')) {
            $widget->filters = ['date_toggles' => $this->date];
        }

        return $widget;
    }

    /**
     * The page date toggle is stored as `date_toggles` on the filters form. Filament
     * has also passed that value nested under the filter name `date`. Read both so
     * Last Month cannot silently fall through to the this_month default.
     */
    private function resolveDashboardDate(): string
    {
        $filters = $this->pageFilters ?? [];
        $nested = \is_array($filters['date'] ?? null) ? $filters['date'] : [];

        foreach ([$filters['date_toggles'] ?? null, $nested['date_toggles'] ?? null, $this->date] as $date) {
            if (\is_string($date) && $date !== '') {
                return $date;
            }
        }

        return 'this_month';
    }

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }
}
