<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\RecoveryLeaderQuery;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\BuildsDateFilterChartSeries;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\RendersDashboardStat;
use App\Filament\Widgets\Dashboard\Concerns\ResolvesDashboardCacheTtl;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class RecoveryLeader extends ChartWidget implements DashboardMetricCard, ExplainsCardQuery
{
    use BuildsDateFilterChartSeries;
    use InteractsWithPageFilters;
    use RendersDashboardStat;
    use ResolvesDashboardCacheTtl;
    use StatHelper;

    /**
     * The max bar uses the Scans leader green. The field uses Filament's neutral grey,
     * which is Color::Zinc (Filament maps gray => Color::Zinc). Zinc is published in
     * oklch, and these are its 500/400 steps copied verbatim rather than re-derived.
     */
    private const LEADER_FILL = 'rgba(34, 197, 94, 0.6)';

    private const LEADER_BORDER = 'rgba(34, 197, 94, 1)';

    private const FIELD_FILL = 'oklch(0.552 0.016 285.938)';

    private const FIELD_BORDER = 'oklch(0.705 0.015 286.067)';

    protected ?string $heading = 'LPR Recovery Leaderboard';

    protected function getTooltipDescription(): ?string
    {
        return 'Your top kit by LPR recoveries in this period. Click to see the full ranking.';
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

    /**
     * Single-series ranking. The subtitle names the measure and omits the window:
     * these options are painted once onto the canvas and are not re-applied when
     * the date filter changes. The window stays in getDescription().
     */
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    legend: {
                        display: false,
                    },
                    subtitle: {
                        display: true,
                        text: 'Top 10 kits by recoveries',
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
                            text: 'Distinct recoveries',
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

        $this->date = $this->resolveMountedDate();
    }

    /**
     * The page date toggle is `date_toggles`, sometimes nested under the filter
     * name `date`, on this widget's filters or on Filament's pageFilters. A date
     * already injected by the expanded card is kept when neither source has a
     * toggle, so mount cannot fall through to this_month.
     */
    private function resolveMountedDate(): string
    {
        $sources = isset($this->filters) ? [$this->filters, $this->pageFilters] : [$this->pageFilters];

        foreach ($sources as $source) {
            $date = $this->dateToggleFrom($source);

            if ($date !== null) {
                return $date;
            }
        }

        if (\is_string($this->date) && $this->date !== '') {
            return $this->date;
        }

        return 'this_month';
    }

    private function dateToggleFrom(mixed $filters): ?string
    {
        if (! \is_array($filters)) {
            return null;
        }

        $nested = \is_array($filters['date'] ?? null) ? $filters['date'] : [];

        foreach ([$filters['date_toggles'] ?? null, $nested['date_toggles'] ?? null] as $date) {
            if (\is_string($date) && $date !== '') {
                return $date;
            }
        }

        return null;
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

    /**
     * The compact-card identifier for this widget. Must match the key in
     * CompanyMetricsOverview::cards() -- see DashboardWidget::getCardKey() for why it is
     * that array and not Dashboard::cardWidgets().
     */
    public function getCardKey(): string
    {
        return 'recovery-leader';
    }

    /**
     * Two-month helper: this month's and last month's leader + runner-up from the
     * already-cached getMonthlyData(formatted: false) buckets. RecoveryLeaderQuery is
     * ORDER BY total DESC LIMIT 10, so leader/runner-up are the first two entries of
     * each of the last two monthly buckets. The collapsed Stat uses
     * leaderboardForSelectedRange() instead, which follows $this->date.
     *
     * @return array{
     *     this_month: array{leader: array{name: string, count: float|int}, runner_up: array{name: string, count: float|int}},
     *     last_month: array{leader: array{name: string, count: float|int}, runner_up: array{name: string, count: float|int}},
     * }
     */
    public function getCardSummary(): array
    {
        $monthlyData = $this->getMonthlyData(formatted: false);

        return [
            'this_month' => $this->summarizeMonth($monthlyData[\count($monthlyData) - 1] ?? []),
            'last_month' => $this->summarizeMonth($monthlyData[\count($monthlyData) - 2] ?? []),
        ];
    }

    /**
     * Name to fractional share for the page date filter, in query order, before
     * summarizeMonth(). Date windows match getDescription() so the collapsed card
     * and the expanded chart never disagree. this_month uses the last
     * getMonthlyData() bucket (full calendar month); today, last_7d, and
     * last_month reuse resolveCardRange() plus queryClickhouse().
     *
     * @return array<string, float>
     */
    protected function rankingForSelectedRange(): array
    {
        $orgId = auth()->user()->org_id;

        if (\in_array($this->date, ['today', 'last_7d', 'last_month'], true)) {
            [$start, $end] = $this->resolveCardRange();

            return $this->queryClickhouse(
                $orgId,
                [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ],
                $this->date === 'last_month' ? 1 : 0,
                $this->date === 'last_month' ? $end : null,
            );
        }

        $monthlyData = $this->getMonthlyData(formatted: false);
        $bucket = $monthlyData[\count($monthlyData) - 1] ?? [];

        return \is_array($bucket) ? $bucket : [];
    }

    /**
     * Leader and runner-up for the page date filter. toStat() reads this shape.
     *
     * @return array{leader: array{name: string, count: float|int}, runner_up: array{name: string, count: float|int}}
     */
    public function leaderboardForSelectedRange(): array
    {
        return $this->summarizeMonth($this->rankingForSelectedRange());
    }

    /**
     * @param  array<string, int|float>  $bucket
     * @return array{leader: array{name: string, count: float|int}, runner_up: array{name: string, count: float|int}}
     */
    protected function summarizeMonth(array $bucket): array
    {
        $sorted = collect($bucket);

        // Counts stay UNCAST. RecoveryLeaderQuery returns round(sum(kit_count/total_count), 2),
        // i.e. fractional attribution shares, and the expanded chart's getDescription() hands
        // the same raw value to number_format() -- which rounds (3.75 -> "4"). Casting to int
        // here truncated instead (3.75 -> "3"), so the collapsed card under-reported every
        // leader and collapsed near-ties relative to the chart it expands into.
        return [
            'leader' => [
                'name' => $sorted->keys()->first(default: 'N/A'),
                'count' => $sorted->first() ?? 0,
            ],
            'runner_up' => [
                'name' => $sorted->keys()->skip(1)->first(default: 'N/A'),
                'count' => $sorted->skip(1)->first() ?? 0,
            ],
        ];
    }

    public function getRgbColor(): array
    {
        return [127, 172, 255];
    }

    /**
     * DEV-2586: the leader's name is restored. It is one TRUNCATED line
     * ({@see leaderKitDescription}) -- the description row costs no height, but a wrapping
     * one would, which is what 304d3c77e removed. The runner-up stays off the card.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        $leaderboard = $this->leaderboardForSelectedRange();

        return $this->dashboardStat(
            Stat::make('LPR Recovery Leaderboard', \number_format($leaderboard['leader']['count']))
                ->description($this->leaderKitDescription($leaderboard['leader']['name'] ?? null))
                ->chart($this->chartSeries())
                ->color('info'),
            $selectedCard,
        );
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getData(): array
    {
        $ranking = $this->rankingForSelectedRange();

        return [
            'datasets' => [
                $this->makeDataset('Distinct Recoveries', \array_values($ranking)),
            ],
            'labels' => \array_keys($ranking),
        ];
    }

    public function getDescription(): string|Htmlable|null
    {
        $orgId = auth()->user()->org_id;
        $timezone = $this->viewerTimezone();

        if ($this->date === 'today') {
            $dates = [
                'start' => now(tz: $timezone)->startOfDay()->toDateString(),
                'end' => now(tz: $timezone)->endOfDay()->toDateString(),
            ];

            $today = collect($this->queryClickhouse($orgId, $dates));

            return new HtmlString(
                'Leader for Today: <b>'.e($today->keys()->first(default: 'N/A')).'</b> with '.\number_format($today->first() ?? 0).
                '<br/>Runner-up for Today: <b>'.e($today->keys()->skip(1)->first(default: 'N/A')).'</b> with '.\number_format($today->skip(1)->first() ?? 0)
            );
        }

        if ($this->date === 'last_7d') {
            $dates = [
                'start' => now(tz: $timezone)->subDays(7)->startOfDay()->toDateString(),
                'end' => now(tz: $timezone)->endOfDay()->toDateString(),
            ];

            $last7d = collect($this->queryClickhouse($orgId, $dates));

            return new HtmlString(
                'Leader for Last 7 Days: <b>'.e($last7d->keys()->first(default: 'N/A')).'</b> with '.\number_format($last7d->first() ?? 0).
                '<br/>Runner-up for Last 7 Days: <b>'.e($last7d->keys()->skip(1)->first(default: 'N/A')).'</b> with '.\number_format($last7d->skip(1)->first() ?? 0)
            );
        }

        if ($this->date === 'last_month') {
            $dates = [
                'start' => now(tz: $timezone)->startOfMonth()->subMonth()->startOfDay()->toDateString(),
                'end' => now(tz: $timezone)->startOfMonth()->subMonth()->endOfMonth()->endOfDay()->toDateString(),
            ];

            $lastMonth = collect($this->queryClickhouse(
                $orgId,
                $dates,
                1,
                now(tz: $timezone)->startOfMonth()->subMonth()->endOfMonth(),
            ));

            return new HtmlString(
                'Leader for Last Month: <b>'.e($lastMonth->keys()->first(default: 'N/A')).'</b> with '.\number_format($lastMonth->first() ?? 0).
                '<br/>Runner-up for Last Month: <b>'.e($lastMonth->keys()->skip(1)->first(default: 'N/A')).'</b> with '.\number_format($lastMonth->skip(1)->first() ?? 0)
            );
        }

        $monthlyData = $this->getMonthlyData(formatted: false);
        $recent = collect($monthlyData[count($monthlyData) - 1] ?? []);

        return new HtmlString(
            'Leader for This Month: <b>'.e($recent->keys()->first(default: 'N/A')).'</b> with '.\number_format($recent->first() ?? 0).
            '<br/>Runner-up for This Month: <b>'.e($recent->keys()->skip(1)->first(default: 'N/A')).'</b> with '.\number_format($recent->skip(1)->first() ?? 0)
        );
    }

    protected function getMonthlyData(bool $formatted = true): array
    {
        $memoKey = $formatted ? 'formatted' : 'raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $data = [];
        $orgId = auth()->user()->org_id;
        $timezone = $this->viewerTimezone();

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $dates = [
                'start' => $targetMonth->clone()->startOfDay()->toDateString(),
                'end' => $targetMonth->clone()->endOfMonth()->endOfDay()->toDateString(),
            ];

            $value = $this->queryClickhouse(
                $orgId,
                $dates,
                $i === 0 ? 0 : 1,
                $i === 0 ? null : $targetMonth->clone()->endOfMonth(),
            );
            $data[] = $value ?? [];
        }

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * Always calls {@see RecoveryLeaderQuery::handle()}. A warm success entry is
     * not a short-circuit, so a ClickHouse throwable stays reachable. Success is
     * stored with {@see baseDashboardCacheTTL()}. A throwable is reported, the
     * success key is forgotten, and the ranking comes back empty. That empty
     * failure is not written under the success key.
     *
     * Closed months pass index 1 plus that month's end so the 8-day settle applies.
     * The open window stays on index 0.
     *
     * @param  array{start: string, end: string}  $dates
     * @return array<string, float|int>
     */
    protected function queryClickhouse(int $orgId, array $dates, int $index = 0, ?\DateTimeInterface $monthEndedAt = null): array
    {
        $start = (string) $dates['start'];
        $end = (string) $dates['end'];
        $key = 'recovery-leader-query:org_id='.$orgId.':start='.$start.':end='.$end;

        try {
            $result = RecoveryLeaderQuery::handle([
                'org_id' => $orgId,
                'start' => $start,
                'end' => $end,
            ]);
            $result = \is_array($result) ? $result : [];

            cache()->put($key, $result, $this->baseDashboardCacheTTL($index, $monthEndedAt));

            return $result;
        } catch (\Throwable $e) {
            report($e);
            cache()->forget($key);

            return [];
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveCardRange(): array
    {
        $timezone = $this->viewerTimezone();

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

    public function getGraphLabels(): array
    {
        $labels = [];
        $timezone = $this->viewerTimezone();

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $labels[] = $targetMonth->format('M \'y');
        }

        return $labels;
    }

    /**
     * The max bar uses the Scans leader green. Every bar tied for the top is emphasised.
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

    public function formatMonthlyData($data): array
    {
        foreach ($data as $key => $item) {
            $data[$key] = collect($item)->values()->sum();
        }

        return $data;
    }

    public function getCardQueryStatements(): array
    {
        if (\in_array($this->date, ['today', 'last_7d', 'last_month'], true)) {
            [$start, $end] = $this->resolveCardRange();
        } else {
            $month = now(tz: $this->viewerTimezone())->startOfMonth();
            $start = $month->clone()->startOfDay();
            $end = $month->clone()->endOfMonth()->endOfDay();
        }

        return [new CardQueryStatement(
            label: 'Headline total (top kit)',
            sql: RecoveryLeaderQuery::handleSql([
                'org_id' => auth()->user()->org_id,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ]),
            note: 'ORDER BY total DESC LIMIT 10 -- the card reads the first row.',
            queryClass: RecoveryLeaderQuery::class,
        )];
    }
}
