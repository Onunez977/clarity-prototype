<?php

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

class ScanStatsBatchQuery extends ClickhouseQuery
{
    /**
     * The raw (non-deduped) scan total per month, keyed `Y-m`.
     *
     * DEV-2651 review-2: grouped by `toStartOfMonth(toTimeZone(scan_hour, $timezone))`, not
     * raw `scan_hour`. This series is now co-plotted with ScanPlateStatsBatchQuery's
     * distinct-plate series on one chart, and that one groups in the viewer's zone. Grouping
     * this one in UTC put the last few hours of every local month in the next bar, so for a
     * non-UTC viewer the same bar could show a raw total BELOW the distinct-plate count -- a
     * structurally impossible reading. Standing alone as ScanCount's own card the mismatch was
     * invisible; juxtaposing the two series is what surfaced it.
     *
     * The bounds are converted to UTC (`toUtcDatetime()`) so the `WHERE` still filters the raw
     * `scan_hour` column and `org_id`/`scan_hour` sort-key pruning is unaffected -- only the
     * SELECT-list grouping expression is zone-aware. ONE zone feeds both the bounds and the
     * grouping: splitting them lets the grouping emit a month key outside the caller's write
     * set, silently dropping the rows in the gap.
     *
     * SCOPE: this makes the card's two series agree with EACH OTHER. Ratio numerators on the
     * Company Metrics cards use the viewer zone. `repo_date` does not move. Claims `ts` is
     * deferred, so the claims funnel stays on Brek's Repo Report UTC clock.
     */
    public static function handleSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        $timezone = ScanPlateStatsBatchQuery::safeTimezone((string) ($arguments['timezone'] ?? 'UTC'));

        $start = ScanPlateStatsBatchQuery::toUtcDatetimeFor((string) $arguments['start'], $timezone, 'start');
        $end = ScanPlateStatsBatchQuery::toUtcDatetimeFor((string) $arguments['end'], $timezone, 'end');

        // DEV-2616, fixed here rather than worked around: this hardcoded `clarity.`, so the
        // query could only ever address the production database name. Local QA provisions
        // per-worktree databases (`clarity_<worktree>`), which made this the one query on the
        // card that could not run there. The alternative — pointing QA at literal clarity/zod
        // databases — de-isolates the QA env, which DEV-2470 records as the wrong trade: a
        // hardcoded prefix is a bug to fix, not a reason to share databases between worktrees.
        // clarityDb() is the existing helper on the parent and is what the sibling
        // ScanPlateStatsBatchQuery has always used. NOTE: DEV-2616 remains open for the other
        // query classes carrying the same flaw; only this one is in this ticket's diff.
        $clarityDb = static::clarityDb();

        return <<<SQL
            SELECT
                toStartOfMonth(toTimeZone(scan_hour, '{$timezone}')) AS month,
                uniqExactMerge(scan_count) AS scan_count
            FROM {$clarityDb}.scan_report_hourly
            WHERE org_id = {$orgId}
              AND scan_hour >= '{$start}'
              AND scan_hour <= '{$end}'
            GROUP BY month
            ORDER BY month
            SQL;
    }

    /**
     * @return array<string, int>
     */
    public static function handle(array $arguments = []): mixed
    {
        $rows = DB::connection('clarity_clickhouse')->select(self::handleSql($arguments));

        $result = [];
        foreach ($rows as $row) {
            $monthKey = date('Y-m', strtotime($row['month']));
            $result[$monthKey] = (int) $row['scan_count'];
        }

        return $result;
    }
}
