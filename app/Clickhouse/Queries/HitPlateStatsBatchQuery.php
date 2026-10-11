<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

/**
 * `clarity.hits_cold` is the ONLY source that carries a plate (`ocr`); the
 * `hit_stats_daily` rollup has no plate column, and `hits_hot` is just a
 * 90-day TTL copy of the same schema, so this query covers the full window
 * from `hits_cold` alone with no hot/cold seam.
 *
 * `uniqExactIf(ocr, ...)` is mandatory here — a bare `count()` is forbidden.
 * `FINAL` cannot collapse rows whose sort key differs, and that key contains
 * the mutable `hit_created_at`, so raw counts run up to ~30% high. `ocr` is
 * immune because the duplicate rows carry the same plate value.
 *
 * Org scoping is deliberately `kit_org_id`, not `hotlist_org_id`. No
 * disposition filter is applied, matching the chlocal `hit_stats_daily`
 * rollup this class parallels.
 *
 * `_peerdb_is_deleted = 0` is added explicitly per
 * `okf/concepts/clickhouse-query-patterns.md` (raw queries must filter CDC
 * tombstones themselves). It is a provable no-op here: `_peerdb_is_deleted`
 * is the native is-deleted engine argument on this `ReplacingMergeTree`, so
 * `FINAL` already drops tombstoned rows.
 */
class HitPlateStatsBatchQuery extends ClickhouseQuery
{
    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * The four per-hit-type deduped-plate aggregates, shared by handle() and handleRange() so the
     * two can never drift apart on which hit types they count or how. Enumerating L/H/S/Z
     * explicitly also structurally excludes the undocumented `F` type.
     */
    private static function aggregates(): string
    {
        return <<<'SQL'
                uniqExactIf(ocr, hit_type = 'L') AS live_hit_count,
                uniqExactIf(ocr, hit_type = 'H') AS historical_hit_count,
                uniqExactIf(ocr, hit_type = 'S') AS staging_live_hit_count,
                uniqExactIf(ocr, hit_type = 'Z') AS staging_historical_hit_count
            SQL;
    }

    /**
     * The shared FROM + WHERE. Both entry points must scope by `kit_org_id` (NOT
     * `hotlist_org_id` — that is the org axis `hit_stats_daily` uses, and the totals must
     * agree) and both must apply the same date bounds, so this lives in one place.
     */
    private static function fromAndWhere(string $clarityDb, int $orgId, string $start, string $end): string
    {
        return <<<SQL
            FROM {$clarityDb}.hits_cold FINAL
            WHERE kit_org_id = {$orgId}
              AND hit_created_at >= toDateTime('{$start}')
              AND hit_created_at <= toDateTime('{$end}')
              AND _peerdb_is_deleted = 0
            SQL;
    }

    /**
     * @return array<string, array{live_hit_count: int, historical_hit_count: int, staging_live_hit_count: int, staging_historical_hit_count: int}>
     */
    public static function handle(array $arguments = []): array
    {
        $orgId = (int) $arguments['org_id'];
        $start = (string) $arguments['start'];
        $end = (string) $arguments['end'];

        self::assertDatetime($start, 'start');
        self::assertDatetime($end, 'end');

        $clarityDb = static::clarityDb();

        $aggregates = self::aggregates();
        $fromAndWhere = self::fromAndWhere($clarityDb, $orgId, $start, $end);

        $sql = <<<SQL
            SELECT
                toStartOfMonth(hit_created_at) AS month,
            {$aggregates}
            {$fromAndWhere}
            GROUP BY month
            ORDER BY month
            SQL;

        $rows = DB::connection('clarity_clickhouse')->select($sql);

        $result = [];
        foreach ($rows as $row) {
            $monthKey = date('Y-m', strtotime($row['month']));
            $result[$monthKey] = [
                'live_hit_count' => (int) $row['live_hit_count'],
                'historical_hit_count' => (int) $row['historical_hit_count'],
                'staging_live_hit_count' => (int) $row['staging_live_hit_count'],
                'staging_historical_hit_count' => (int) $row['staging_historical_hit_count'],
            ];
        }

        return $result;
    }

    public static function handleRangeSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        $start = (string) $arguments['start'];
        $end = (string) $arguments['end'];

        self::assertDatetime($start, 'start');
        self::assertDatetime($end, 'end');

        $aggregates = self::aggregates();
        $fromAndWhere = self::fromAndWhere(static::clarityDb(), $orgId, $start, $end);

        return <<<SQL
            SELECT
            {$aggregates}
            {$fromAndWhere}
            SQL;
    }

    /**
     * Fetch deduped plate counts for an arbitrary date range (used for today/last_7d).
     *
     * @return array{live_hit_count: int, historical_hit_count: int, staging_live_hit_count: int, staging_historical_hit_count: int}
     */
    public static function handleRange(array $arguments = []): array
    {
        $rows = DB::connection('clarity_clickhouse')->select(self::handleRangeSql($arguments));

        if (empty($rows)) {
            return [
                'live_hit_count' => 0,
                'historical_hit_count' => 0,
                'staging_live_hit_count' => 0,
                'staging_historical_hit_count' => 0,
            ];
        }

        return [
            'live_hit_count' => (int) $rows[0]['live_hit_count'],
            'historical_hit_count' => (int) $rows[0]['historical_hit_count'],
            'staging_live_hit_count' => (int) $rows[0]['staging_live_hit_count'],
            'staging_historical_hit_count' => (int) $rows[0]['staging_historical_hit_count'],
        ];
    }

    /**
     * Strict allowlist for the interpolated strings. addslashes() is not a
     * SQL escape; this guards against any future caller passing
     * user-influenced input through to ClickHouse.
     */
    private static function assertDatetime(string $value, string $field): void
    {
        if (preg_match(self::DATETIME_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field} datetime: {$value}");
        }
    }
}
