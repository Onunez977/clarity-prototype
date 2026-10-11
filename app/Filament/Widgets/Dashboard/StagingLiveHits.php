<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\SharedHitStatsData;
use Illuminate\Support\Carbon;

class StagingLiveHits extends DashboardWidget implements ExplainsCardQuery
{
    use SharedHitStatsData;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Staging Live Hits';

    protected function getTooltipDescription(): ?string
    {
        return 'Scans matching a staged assignment at scan time, once per plate. The total below counts every hit.';
    }

    public function getDatasetLabel(): string
    {
        return 'Staging Live Hits';
    }

    public function getRgbColor(): array
    {
        return [255, 185, 0];
    }

    public function getHitStatsColumn(): string
    {
        return 'staging_live_hit_count';
    }

    /**
     * Org total for every date arm, including last month. Reads rollup key 0 from the
     * same window the leaderboard ranks, so the headline and the list share one scan.
     */
    public function getCardTotal(): int
    {
        $orgId = (int) auth()->user()->org_id;
        [$start, $end, $timezone] = $this->resolveRangeAndTimezone();
        $rollup = StagingLiveHitLeaderQuery::rememberedRange($orgId, $start, $end, $timezone);

        return (int) ($rollup['totals'][0] ?? 0);
    }

    public function getCardQueryStatements(): array
    {
        $orgId = (int) auth()->user()->org_id;
        [$start, $end, $timezone] = $this->resolveRangeAndTimezone();

        return [new CardQueryStatement(
            label: 'Headline total (deduped plates)',
            sql: StagingLiveHitLeaderQuery::handleRangeSql([
                'org_id' => $orgId,
                'start' => $start,
                'end' => $end,
                'timezone' => $timezone,
            ]),
            note: 'GROUP BY kit_id WITH ROLLUP. The card reads totals key 0, the org uniqExactIf.',
            queryClass: StagingLiveHitLeaderQuery::class,
        )];
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
     * @return array{0: Carbon, 1: Carbon}
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

    protected function currentPeriodTotal(): int
    {
        [$start, $end] = $this->resolveCardRange();

        $ttl = $this->getCacheTTL();

        if ($this->date === 'last_month') {
            $timezone = $this->sanitizedViewerTimezone();
            $monthEndedAt = now(tz: $timezone)->startOfMonth()->subMonth()->endOfMonth();
            $ttl = $this->getCacheTTL(1, $monthEndedAt);
        }

        return (int) (cache()->remember(
            $this->buildWidgetCacheKey($this->date, auth()->user()->org_id),
            $ttl,
            fn () => $this->getRangeTotal($start->toDateTimeString(), $end->toDateTimeString())
        ) ?? 0);
    }

    public function getGraphLabels(): array
    {
        $labels = [];
        $timezone = $this->sanitizedViewerTimezone();

        for ($i = 12; $i >= 0; $i--) {
            $labels[] = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('M \'y');
        }

        return $labels;
    }

    protected function getHighlightedMonthsFromFilter(): array
    {
        $timezone = $this->sanitizedViewerTimezone();

        return match ($this->date) {
            'last_month' => [now(tz: $timezone)->startOfMonth()->subMonth()->format('M \'y')],
            default => [now(tz: $timezone)->format('M \'y')],
        };
    }

    private function sanitizedViewerTimezone(): string
    {
        return ScanPlateStatsBatchQuery::safeTimezone((string) (auth()->user()->timezone ?? 'UTC'));
    }

    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return 0;
    }
}
