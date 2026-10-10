<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use Illuminate\Support\Carbon;

trait BuildsDateFilterChartSeries
{
    /**
     * `users.dti` is unvalidated. {@see ScanPlateStatsBatchQuery::safeTimezone()}
     * is the allowlist: unlisted ids become UTC and this method does not throw.
     */
    protected function viewerTimezone(): string
    {
        return ScanPlateStatsBatchQuery::safeTimezone((string) (auth()->user()->timezone ?? 'UTC'));
    }

    /**
     * The current window for $this->date, using the exact expressions already used by
     * getDescription()/getMonthlyData() -- never re-derive the month arithmetic (DEV-1927).
     *
     * The zone is {@see viewerTimezone()}. A raw `users.dti` value such as `NotAReal/Zone`
     * throws inside `now(tz:)` and takes down Company Metrics before later cards render.
     *
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

    /**
     * Compact-card sparkline from the widget's existing 13-month getMonthlyData()
     * series. No extra ClickHouse grouping -- the date filter still changes the
     * headline via currentPeriodTotal()/getCardTotal(), which is what the expanded
     * chart already showed (DEV-2324 Q2).
     *
     * @return array<int, float>
     */
    protected function chartSeries(): array
    {
        if (! \method_exists($this, 'getMonthlyData')) {
            return [0.0, 0.0];
        }

        $points = [];

        foreach ($this->getMonthlyData(formatted: false) as $bucket) {
            $points[] = \is_array($bucket) ? (float) \array_sum($bucket) : (float) $bucket;
        }

        if (\count($points) < 2) {
            \array_unshift($points, 0.0);
        }

        return $points;
    }
}
