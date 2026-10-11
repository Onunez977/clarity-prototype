<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Models\Views\StagingOrgsHotlistCount;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class HotlistCount extends BaseWidget
{
    /**
     * An ARRAY, not the string 'full': Filament normalises a SCALAR span to `['lg' => $span]`,
     * which left this row at half the page width below `lg`. `default` alone is enough --
     * `.fi-grid-col`'s base rule is not media-queried. See docs/investigations/DEV-2460/
     * layout-findings.md.
     */
    protected int|string|array $columnSpan = [
        'default' => 'full',
    ];

    protected ?string $pollingInterval = null;

    /** @var array{active: int, cameras: int}|null */
    private ?array $kitUserCounts = null;

    private bool $failSoft = true;

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    /**
     * Six stats since DEV-2460 (Active Kit Users joined the row). Six abreast is `xl`-only:
     * with the default-open 320px sidebar it needs roughly a 127px card, which lands around a
     * 1240px viewport -- so it would fit in the top ~40px of `lg` and nowhere else in it. At
     * 1024, the bulk of `lg`, six gives a 92px card and breaks four text nodes. Not worth a
     * breakpoint that changes shape 40px before `xl` does.
     *
     * `md` and `lg` are both 3 because the sidebar hides below `lg`, which makes 900px
     * roomier than 1024px (868px of grid vs 672px). 3 rather than 4 because six divides
     * evenly by 3, while 4 still costs two rows and leaves a ragged 4+2.
     *
     * theme.css hangs the Company Metrics grid's 160px height floor off this row and trims
     * this row's padding to 20px/side; both notes there matter if you change these values.
     * Measured tables, the mid-word-break history and the pre-DEV-2460 baseline are in
     * docs/investigations/DEV-2460/layout-findings.md -- re-measure there, do not guess here.
     */
    protected function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'md' => 3,
            'lg' => 3,
            'xl' => 6,
        ];
    }

    /**
     * Active Kit Users' two numbers come from the KitUsers widget, which owns those cached
     * ClickHouse reads. Composed as a plain adapter (never Livewire-mounted) -- the same
     * pattern CompanyMetricsOverview uses for every card.
     *
     * Reuse, not re-implementation, is what satisfies the DEV-2460 requirement that the
     * per-render ClickHouse round-trip count not regress: these two reads already happened
     * when KitUsers was itself a grid card, so relocating them is net-neutral. Duplicating
     * the queries here under different cache keys WOULD be the regression.
     *
     * FAIL-SOFT PARITY. Every other number in this widget survives a ClickHouse outage:
     * fetchHotlistCounts() catches Throwable and falls back to zeros so the header row still
     * renders. KitUsers' two accessors document the OPPOSITE policy on purpose ("letting a
     * ClickHouse failure surface" is better for a single card). Composing them here without
     * reconciling that would let a CH blip take out the four hotlist numbers too, which is a
     * regression in this widget's documented behaviour rather than a neutral relocation --
     * so the two calls are wrapped to match the host widget. The catch is OUTSIDE
     * KitUsers' cache()->remember(), so a failure is never cached as a fabricated 0; it is
     * reported and degrades for this render only.
     *
     * @return array{active: int, cameras: int}
     */
    private function kitUserCounts(): array
    {
        if ($this->kitUserCounts !== null) {
            return $this->kitUserCounts;
        }

        $kitUsers = $this->kitUsersSource();

        // Each leg degrades on its own. Wrapping both together threw away a value that had
        // already been computed when only the other one failed.
        return $this->kitUserCounts = [
            'active' => $this->degradesToZero(static fn (): int => $kitUsers->activeKitUsersCount()),
            'cameras' => $this->degradesToZero(static fn (): int => $kitUsers->cameraKitsCount()),
        ];
    }

    /**
     * The KitUsers adapter this widget sources its two counts from.
     *
     * A seam, not indirection for its own sake: the degradation below can only be tested
     * deterministically if a read can be MADE to fail. Relying on an unseeded cache key
     * instead was vacuous in CI, where ClickHouse is live and the query simply returns 0 --
     * indistinguishable from the degraded value (round 4).
     */
    protected function kitUsersSource(): KitUsers
    {
        return new KitUsers;
    }

    /**
     * @param  callable(): int  $read
     */
    private function degradesToZero(callable $read): int
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            if (! $this->failSoft) {
                throw $e;
            }

            report($e);

            return 0;
        }
    }

    public function totalDirect(): int
    {
        return $this->hotlistCounts()['total_direct'];
    }

    public function totalDirectWithPlates(): int
    {
        return $this->hotlistCounts()['direct_with_plates'];
    }

    public function missingPlates(): int
    {
        return $this->hotlistCounts()['missing_plates'];
    }

    public function totalStaged(bool $withStagingOrgs = false): int
    {
        $staged = $this->hotlistCounts()['total_staged'];

        if ($withStagingOrgs) {
            $staged += StagingOrgsHotlistCount::getCount(auth()->user()->org->stagingOrgIds()->toArray());
        }

        return $staged;
    }

    /**
     * Single combined ClickHouse query for all hotlist counts.
     * Reduces 4 separate FINAL dedup passes to 1.
     */
    protected function hotlistCounts(): array
    {
        return cache()->remember(
            'hotlist-count:combined:org='.auth()->user()->org_id,
            now()->endOfHour(),
            fn () => $this->fetchHotlistCounts()
        );
    }

    protected function fetchHotlistCounts(): array
    {
        try {
            $row = ClickhouseHotlist::query()
                ->forOrg(auth()->user()->org_id)
                ->open()
                ->selectRaw("
                    countDistinctIf(vin, is_staged = 0) as total_direct,
                    countDistinctIf(vin, is_staged = 0 AND length(plate) >= 3) as direct_with_plates,
                    countIf(is_staged = 0 AND length(coalesce(plate, '')) < 3) as missing_plates,
                    countDistinctIf(vin, is_staged = 1 AND length(plate) >= 3) as total_staged
                ")
                ->first();

            if (! $row) {
                return self::emptyCountsArray();
            }

            return [
                'total_direct' => (int) $row->total_direct,
                'direct_with_plates' => (int) $row->direct_with_plates,
                'missing_plates' => (int) $row->missing_plates,
                'total_staged' => (int) $row->total_staged,
            ];
        } catch (\Throwable $e) {
            if (! $this->failSoft) {
                throw $e;
            }

            report($e);

            return self::emptyCountsArray();
        }
    }

    protected static function emptyCountsArray(): array
    {
        return [
            'total_direct' => 0,
            'direct_with_plates' => 0,
            'missing_plates' => 0,
            'total_staged' => 0,
        ];
    }

    protected function getStats(): array
    {
        $kitUsers = $this->kitUserCounts();

        $missingPlatePercentage = 0;
        if ($this->totalDirect() > 0) {
            $missingPlatePercentage = \round($this->missingPlates() / $this->totalDirect() * 100);
        }

        // Marks this row -- and ONLY this row -- for the overflow-wrap rule in theme.css.
        // Scoped deliberately: the obvious selector, `:not(.big-stat-chart)`, is the WIDEST
        // scope, not the narrowest -- .big-stat-chart is the Company Metrics grid, so
        // negating it catches all 11 other stats-overview consumers across both panels.
        return \array_map(
            fn (Stat $stat): Stat => $stat->extraAttributes(
                ['class' => 'company-metrics-header-stat'],
                merge: true,
            ),
            [
                Stat::make('Total Open Assignments', \number_format($this->totalDirect()))
                    ->extraAttributes([
                        'class' => 'full-height-widget',
                        'data-stat' => 'Direct: '.$this->totalDirect(),
                        'data-description' => 'Count of unique open assignments (by VIN) currently on your direct hotlist.',
                    ]),

                Stat::make('Open Assignments with Plates', \number_format($this->totalDirectWithPlates()))
                    ->description('Direct Hotlist Size')
                    ->extraAttributes([
                        'class' => 'highlighted-widget',
                        'data-stat' => 'Direct with Plates: '.$this->totalDirectWithPlates(),
                        'data-description' => 'Count of unique open direct assignments that have a license plate on file. This is your direct hotlist size.',
                    ]),

                Stat::make('Open Assignments Missing Plates', \number_format($this->missingPlates()))
                    ->extraAttributes([
                        'class' => 'full-height-widget',
                        'data-stat' => 'Missing Plates: '.$this->missingPlates(),
                        'data-description' => 'Count of open direct assignments that are missing a valid license plate.',
                    ]),

                Stat::make('Missing Plates Percentage', $missingPlatePercentage.'%')
                    ->description('Missing Plates / Total Open Assignments')
                    ->extraAttributes([
                        'data-stat' => 'Missing Plates Percentage: '.$missingPlatePercentage.'%',
                        'data-description' => 'Percentage of open direct assignments that are missing plates, calculated as Missing Plates divided by Total Open Assignments.',
                    ]),

                // POSITION 5 OF 6, between Missing Plates Percentage and Staging -- read off the
                // mockup (DEV-2409 attachment 24421), not appended. See
                // docs/investigations/DEV-2460/mockup-confirmation.md.
                //
                // The secondary figure is cameraKitsCount(), which is a genuine change from the old
                // KitUsers card's "<N> inactive kits". Do not "restore" inactiveKitsCount() on the
                // assumption the old card was right -- the mockup asks for the total here.
                //
                // THE LABEL SAYS "kits", NOT "camera kits" (the mockup's wording), DELIBERATELY.
                // cameraKitsCount() has no kit_type predicate, and okf/concepts/kit.md reserves
                // "camera kits" for kit_type = 1 (~3,535 of ~4,755), so the unfiltered total
                // overstates it by about a quarter. Filtering was rejected here: the same method
                // backs KitUsers' own card and inactiveKitsCount(), so it would change an existing
                // metric's value (AC-6) and need a new cache key. Whether it should be filtered at
                // all is a product question, raised separately.
                //
                // SECOND caveat on the same number, also deliberately not fixed here:
                // clarity.kits is a ReplacingMergeTree CDC mirror, and okf/models/clickhouse/kits.md
                // says it must be read with FINAL. ClickhouseKit applies only the soft-delete
                // filter, so the count can include superseded row versions -- agent QA reproduced
                // it live as 8 for 4 real kits until a merge settled the parts. Only the TOOLTIP
                // hedges this ("kit records"); the visible line below still reads "N kits", which
                // is a definitive-sounding count over an unmerged read. Hedging the visible line
                // needs ->final(), and that is an AC-6 value change.
                Stat::make('Active Kit Users', \number_format($kitUsers['active']))
                    ->description(\number_format($kitUsers['cameras']).' kits')
                    ->extraAttributes([
                        'data-stat' => 'Active Kit Users: '.$kitUsers['active'],
                        'data-description' => 'Kit users that have scanned recently, against your organization\'s kit records (all kit types).',
                    ]),

                Stat::make('Staging', \number_format($this->totalStaged(withStagingOrgs: true)))
                    ->description('Approximate Staging Hotlist Size')
                    ->extraAttributes([
                        'data-stat' => 'Staging: '.$this->totalStaged(),
                        'data-stat-with' => 'Staging: '.$this->totalStaged(withStagingOrgs: true),
                        'data-description' => 'Approximate count of staged assignments with plates, including assignments from staging organizations.',
                    ]),
            ]);
    }

    /**
     * Rethrow a ClickHouse failure instead of rendering 0, for callers that must not
     * mistake an outage for a real count (Otto, DEV-2706).
     */
    public function failLoudly(): static
    {
        $this->failSoft = false;

        return $this;
    }

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }
}
