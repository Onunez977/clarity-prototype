<?php

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Supplies the TOTAL half of the Company Metrics hit tiles; HitPlateStatsBatchQuery
 * supplies the deduped half. Both MUST resolve the database the same way — this class
 * previously hardcoded `clarity.` while its sibling used clarityDb(), so under any
 * environment where CLICKHOUSE_DATABASE_CLARITY is not the literal `clarity` (the
 * standard per-worktree local-CH QA setup provisions `clarity_<worktree>`) one tile
 * would read its two numbers from two different databases.
 */
class HitStatsBatchQuery extends ClickhouseQuery
{
    /**
     * The four per-hit-type total aggregates, shared by handle() and handleRange() so the two
     * cannot drift apart on what they sum.
     */
    private static function aggregates(): string
    {
        return <<<'SQL'
                sum(live_hit_count) AS live_hit_count,
                sum(historical_hit_count) AS historical_hit_count,
                sum(staging_live_hit_count) AS staging_live_hit_count,
                sum(staging_historical_hit_count) AS staging_historical_hit_count
            SQL;
    }

    /**
     * The shared FROM + WHERE. `org_id` on this rollup is the KIT org (its chlocal MV populates
     * it from `kits.org_id`), which is why HitPlateStatsBatchQuery scopes by `kit_org_id` — the
     * two halves of a tile must agree on the org axis as well as the database.
     */
    private static function fromAndWhere(string $clarityDb, int $orgId, string $start, string $end): string
    {
        return <<<SQL
            FROM {$clarityDb}.hit_stats_daily FINAL
            WHERE org_id = {$orgId}
              AND date >= '{$start}'
              AND date <= '{$end}'
            SQL;
    }

    public static function handle(array $arguments = []): mixed
    {
        $orgId = (int) $arguments['org_id'];
        $start = addslashes($arguments['start']);
        $end = addslashes($arguments['end']);

        $clarityDb = static::clarityDb();

        $aggregates = self::aggregates();
        $fromAndWhere = self::fromAndWhere($clarityDb, $orgId, $start, $end);

        $sql = <<<SQL
            SELECT
                toStartOfMonth(date) AS month,
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

    /**
     * Fetch summed counts for an arbitrary date range (used by description: today/last_7d).
     */
    public static function handleRange(array $arguments = []): mixed
    {
        $orgId = (int) $arguments['org_id'];
        $start = addslashes($arguments['start']);
        $end = addslashes($arguments['end']);

        $clarityDb = static::clarityDb();

        $aggregates = self::aggregates();
        $fromAndWhere = self::fromAndWhere($clarityDb, $orgId, $start, $end);

        $sql = <<<SQL
            SELECT
            {$aggregates}
            {$fromAndWhere}
            SQL;

        $rows = DB::connection('clarity_clickhouse')->select($sql);

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
}
