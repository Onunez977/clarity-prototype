<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use App\Filament\Widgets\Dashboard\Concerns\ResolvesDashboardCacheTtl;
use Illuminate\Support\Facades\DB;

/**
 * One window of deduped Staging Live (`hit_type = 'S'`) plate hits, per kit, plus the org total.
 *
 * `uniqExactIf(ocr, hit_type = 'S')` is mandatory here — a bare `count()` is forbidden.
 * `clarity.hits_cold` is a `ReplacingMergeTree` whose sort key is
 * `(hotlist_org_id, toYYYYMM(hit_created_at), hit_created_at, id)`. `hit_created_at` is
 * mutable and part of that key, so two rows for the same hit `id` with different
 * `hit_created_at` do NOT collapse under `FINAL` — a bare `count()` runs up to ~30%
 * high (see `HitPlateStatsBatchQuery`). `ocr` is immune because the duplicate rows
 * carry the same plate value.
 *
 * Org scoping is deliberately `kit_org_id` (the scan's org), NOT the hotlist org —
 * it must match the sibling Staging Live Hits card so the two totals agree.
 * `forOrg()` drops Staging Live.
 *
 * `GROUP BY kit_id WITH ROLLUP` answers the org total and the per-kit ranking in one
 * scan. Key `0` is that rollup row. A plate seen by two kits counts in both kit rows
 * and once in the org total, so the kit rows must never be summed into the headline.
 *
 * `hits_cold` carries both `kit_id` and the denormalised `kit_name`, so no join to
 * `clarity.kits` is needed for the label. The result is keyed by `kit_id`, never
 * `kit_name` — a kit name is not unique within an org.
 *
 * Bounds arrive as naive viewer-local `Y-m-d H:i:s` strings. `hit_created_at` is UTC,
 * so {@see handleRangeSql()} converts them with
 * {@see ScanPlateStatsBatchQuery::toUtcDatetimeFor()} before interpolation. The zone
 * is not written into the SQL. `safeTimezone()` is the caller's choice for month
 * arms; this class does not apply it.
 */
class StagingLiveHitLeaderQuery extends ClickhouseQuery
{
    use ResolvesDashboardCacheTtl;

    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * The shared FROM + WHERE. Scopes by `kit_org_id` and applies the UTC bounds.
     */
    private static function fromAndWhere(string $clarityDb, int $orgId, string $start, string $end): string
    {
        return <<<SQL
            FROM {$clarityDb}.hits_cold FINAL
            WHERE kit_org_id = {$orgId}
              AND hit_type = 'S'
              AND hit_created_at >= toDateTime('{$start}')
              AND hit_created_at <= toDateTime('{$end}')
              AND _peerdb_is_deleted = 0
            SQL;
    }

    /**
     * Satisfies {@see ClickhouseQuery::handle()}. The ranking no longer uses this
     * as a LIMIT 10 query. Callers read {@see rememberedRange()}.
     *
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    public static function handle(array $arguments = []): array
    {
        return self::handleRange($arguments);
    }

    /**
     * Per-kit Staging Live plate counts for one window, plus the org rollup row.
     *
     * `$timezone` is the zone that minted `$start` and `$end`. It is used only to
     * convert those bounds to UTC. It is not interpolated, and it is not passed
     * through `safeTimezone()`.
     */
    public static function handleRangeSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        $timezone = (string) $arguments['timezone'];

        $start = ScanPlateStatsBatchQuery::toUtcDatetimeFor((string) $arguments['start'], $timezone, 'start');
        $end = ScanPlateStatsBatchQuery::toUtcDatetimeFor((string) $arguments['end'], $timezone, 'end');

        $fromAndWhere = self::fromAndWhere(static::clarityDb(), $orgId, $start, $end);

        return <<<SQL
            SELECT
                kit_id,
                GROUPING(kit_id) AS is_rollup,
                if(anyLast(kit_name) = '', concat('Kit ', toString(kit_id)), anyLast(kit_name)) AS name,
                uniqExactIf(ocr, hit_type = 'S') AS total
            {$fromAndWhere}
            GROUP BY kit_id WITH ROLLUP
            SQL;
    }

    /**
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    public static function handleRange(array $arguments = []): array
    {
        $rows = DB::connection('clarity_clickhouse')->select(self::handleRangeSql($arguments));

        if ($rows === []) {
            return ['totals' => [0 => 0], 'names' => []];
        }

        $totals = [];
        $names = [];
        $orgTotal = 0;

        foreach ($rows as $row) {
            if (((int) $row['is_rollup']) === 1) {
                $orgTotal = (int) $row['total'];

                continue;
            }

            $kitId = (int) $row['kit_id'];

            if ($kitId === 0) {
                continue;
            }

            $totals[$kitId] = (int) $row['total'];
            $names[$kitId] = (string) $row['name'];
        }

        $totals[0] = $orgTotal;

        return ['totals' => $totals, 'names' => $names];
    }

    /**
     * Month arms share the sanitised bucket zone. Day arms keep the raw viewer zone
     * and the bounds the caller already cut with {@see resolveCardRange()}.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function boundsForCardDate(string $date, string $viewerTimezone, string $rawStart, string $rawEnd): array
    {
        if (in_array($date, ['this_month', 'last_month'], true)) {
            $bucketZone = ScanPlateStatsBatchQuery::safeTimezone($viewerTimezone);

            $window = $date === 'last_month'
                ? ScanPlateStatsBatchQuery::lastMonthWindow($bucketZone)
                : ScanPlateStatsBatchQuery::currentMonthWindow($bucketZone);

            return [$window['start'], $window['end'], $bucketZone];
        }

        return [$rawStart, $rawEnd, $viewerTimezone];
    }

    /**
     * Class-neutral window key. No class basename, so the Staging Live Hits headline
     * and the leaderboard read one entry. `$timezone` is part of the key because the
     * same naive `$start`/`$end` strings are a different UTC window in another zone.
     *
     * Pass the same `$start`, `$end`, and `$timezone` that {@see handleRange()} receives.
     */
    public static function sharedRangeCacheKey(int $orgId, string $start, string $end, string $timezone): string
    {
        self::assertDatetime($start, 'start');
        self::assertDatetime($end, 'end');

        $key = "staging-live-hits:range-v1:org={$orgId}:{$start}:{$end}:tz={$timezone}";

        return str_replace(' ', '', $key);
    }

    /**
     * The shared range rollup. A hit returns the cached {@see handleRange()} payload.
     * A miss runs it and stores it under {@see sharedRangeCacheKey()}.
     *
     * TTL is the ladder's open arm (10 minutes, 10 seconds while impersonating).
     * The callback does not catch. A failure writes nothing.
     *
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    public static function rememberedRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return cache()->remember(
            self::sharedRangeCacheKey($orgId, $start, $end, $timezone),
            (new static)->baseDashboardCacheTTL(0),
            fn (): array => self::handleRange([
                'org_id' => $orgId,
                'start' => $start,
                'end' => $end,
                'timezone' => $timezone,
            ]),
        );
    }

    /**
     * Strict allowlist for the interpolated datetime strings. addslashes() is not a
     * SQL escape; this guards against any future caller passing user-influenced
     * input through to ClickHouse.
     */
    private static function assertDatetime(string $value, string $field): void
    {
        if (preg_match(self::DATETIME_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field} datetime: {$value}");
        }
    }
}
