<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\BillingEventType;

/**
 * Single-pass ClickHouse claims funnel behind the "Unique LPR Case Claims"
 * dashboard card (DEV-2463). Deliberately NOT a ClickhouseQuery subclass:
 * that base's query() swallows Throwable and returns [] in production and
 * testing, but a genuine zero is a normal reading here (many entitled orgs
 * have zero claims in a given window) -- a failed query must surface rather
 * than render indistinguishably as 0. Failures propagate from the
 * clarity_clickhouse select.
 *
 * DEV-2659: one scan of `{clarity_db}.billing_claim_events_v`. `org_id` is
 * aliased to `forwarder_org_id`. Exclusion is `is_internal = 0`. This class
 * does not read `clarity.orgs`.
 *
 * `is_internal` is frozen when `clarity.billing_claim_events_mv` refreshes,
 * from `zod.public_orgs FINAL` across all org types. A later org rename does
 * not rewrite historical rows. API-key changes in that MV re-read only an
 * 8-day window (`today() - 8`); older key moves are not backfilled by the
 * hourly refresh. The view does not project `_peerdb_is_deleted`, so a
 * tombstone predicate is unexpressible on `_v`. Tombstones are dropped inside
 * the view (`FINAL` where `_peerdb_is_deleted = 0`).
 *
 * `ts BETWEEN` stays. `event_date BETWEEN` is a pair of PHP `toDate('Y-m-d')`
 * literals covering that UTC span — the same shape as LprRepossessionsQuery's
 * `{floor}` placeholder — so `ORDER BY (event_date, id)` can prune. `today` /
 * `last_7d` are not whole months, so the epoch bound is still required.
 * Every date boundary is explicit UTC.
 *
 * All three funnel stages come from that one scan: `count()`, `countIf(success)`,
 * and `uniqExactIf` of BillingClaimDedup::tripleSql() in the same SELECT.
 * The triple keeps `forwarder_org_id`.
 *
 * DEV-2465's windowWithPreClaimScans()/monthlySeriesWithPreClaimScans() variants return
 * {deduped, pre_claim} only -- all_claims/successful are read by NOTHING downstream (rate()
 * only ever reads deduped/pre_claim), so review round 3 (S1) deleted the `stats` CTE that
 * computed them. `oc` is still referenced twice: once by `flagged` (the deduped/pre_claim
 * aggregate) and once by `h`'s own pruning subquery (`hotlist_id IN (SELECT ... FROM oc)`).
 * That prune trades +40.5M read_rows for a LEFT JOIN build side pruned to only the
 * hotlist ids this window actually claimed: peak memory 233.9 MiB vs 499.6 MiB unpruned
 * (measured; 233.9 MiB x 29 = ~6.6 GiB vs 499.6 MiB x 29 = ~14.2 GiB, i.e. 29 entitled orgs at ONE cold key each), because the JOIN build side
 * can't spill (max_bytes_in_join/join_algorithm are unset on clarity_clickhouse). The
 * hits_cold join and `h.hotlist_id != 0` are unchanged. Comment 41325 did not validate
 * that join; this class does not claim a new production rate for it.
 *
 * AC-5: the cold-start ceiling on the old scan is removed by reading
 * `clarity.billing_claim_events_v` (`is_internal = 0`; `org_id = 0` is a sentinel,
 * not an org). This class does not add a shared cache key.
 */
class UniqueLprCaseClaimsQuery
{
    /**
     * Single-window scalar for the `today` / `last_7d` filters. One round trip.
     * $orgId of 0 (or null) means platform-wide; otherwise the org is counted
     * on EITHER the forwarder or the agent side.
     *
     * FAIL-OPEN DEFAULT, recorded deliberately (review round 2, all three lanes). `null`
     * becomes 0, and 0 means EVERY org -- "no tenant" reads as "all tenants" in a
     * tenant-scoped query. This is currently unreachable: users.org_id IS nullable, but the
     * widget evaluates DashboardWidget::buildWidgetCacheKey(string, int $orgId) first, which
     * TypeErrors on null, so a null-org viewer gets a 500 rather than another tenant's data.
     * That safety lives in a DIFFERENT class's type hint, not in this one. A new caller --
     * a console warmer, a queued job, a relaxed key builder -- would silently get the whole
     * platform's claims. Operator decision 2026-09-08: leave the signature, record the trap.
     * Prefer passing an explicit org id; treat platform-wide as a deliberate act.
     *
     * @return array{all_claims: int, successful: int, deduped: int}
     */
    public static function window(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = self::run(self::windowSql($orgId, $start, $end));

        return self::normalizeRow($rows[0] ?? []);
    }

    /**
     * 13-month, UTC `toStartOfMonth`-bucketed series for the sparkline. One
     * round trip. `event_date` is the leading sort key on the view's table, so
     * the PHP `toDate('Y-m-d')` bound can prune; `ts BETWEEN` still applies.
     *
     * @return array<string, array{all_claims: int, successful: int, deduped: int}> keyed by 'Y-m-01'
     */
    public static function monthlySeries(?int $orgId, CarbonInterface $through): array
    {
        $rows = self::run(self::monthlySeriesSql($orgId, $through));

        $series = [];
        foreach ($rows as $row) {
            $series[substr((string) $row['bucket'], 0, 10)] = self::normalizeRow($row);
        }

        return $series;
    }

    /**
     * DEV-2465: the pre-claim-scan numerator alongside window()'s denominator, one round
     * trip. Deliberately does NOT return all_claims/successful -- nothing downstream reads
     * them (rate() only ever reads deduped/pre_claim), so this shape never computes them
     * (review round 3, S1). `kept` still expands twice via `oc`'s two references -- see the
     * class docblock. $orgId semantics match window().
     *
     * @return array{deduped: int, pre_claim: int}
     */
    public static function windowWithPreClaimScans(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = self::run(self::windowWithPreClaimScansSql($orgId, $start, $end));

        return self::normalizePreClaimRow($rows[0] ?? []);
    }

    /**
     * DEV-2465: monthlySeries()'s pre-claim-scan counterpart, one round trip. Same
     * {deduped, pre_claim}-only shape as windowWithPreClaimScans() -- see that method's
     * docblock.
     *
     * @return array<string, array{deduped: int, pre_claim: int}> keyed by 'Y-m-01'
     */
    public static function monthlySeriesWithPreClaimScans(?int $orgId, CarbonInterface $through): array
    {
        $rows = self::run(self::monthlySeriesWithPreClaimScansSql($orgId, $through));

        $series = [];
        foreach ($rows as $row) {
            $series[substr((string) $row['bucket'], 0, 10)] = self::normalizePreClaimRow($row);
        }

        return $series;
    }

    public static function windowSql(?int $orgId, CarbonInterface $start, CarbonInterface $end): string
    {
        return self::buildSql(false, false, $orgId ?? 0, $start, $end);
    }

    public static function windowWithPreClaimScansSql(?int $orgId, CarbonInterface $start, CarbonInterface $end): string
    {
        return self::buildSql(false, true, $orgId ?? 0, $start, $end);
    }

    /**
     * The 13-month window arithmetic lives here ONLY -- monthlySeries() and its pre-claim
     * sibling read it back through these so the emitted and executed SQL cannot drift.
     */
    public static function monthlySeriesSql(?int $orgId, CarbonInterface $through): string
    {
        [$start, $end] = self::seriesWindow($through);

        return self::buildSql(true, false, $orgId ?? 0, $start, $end);
    }

    public static function monthlySeriesWithPreClaimScansSql(?int $orgId, CarbonInterface $through): string
    {
        [$start, $end] = self::seriesWindow($through);

        return self::buildSql(true, true, $orgId ?? 0, $start, $end);
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private static function seriesWindow(CarbonInterface $through): array
    {
        $end = $through->clone()->utc()->endOfMonth();

        return [$end->clone()->subMonthsNoOverflow(12)->startOfMonth(), $end];
    }

    private static function buildSql(
        bool $bucketed,
        bool $withPreClaimScans,
        int $orgId,
        CarbonInterface $start,
        CarbonInterface $end,
    ): string {
        $utcStart = $start->clone()->utc();
        $utcEnd = $end->clone()->utc();

        // `{d_from}` / `{d_to}` match LprRepossessionsQuery's `{floor}` idiom:
        // a PHP toDate('Y-m-d') literal, not a ClickHouse fold of the epoch.
        return strtr(self::sql($bucketed, $withPreClaimScans), [
            '{clarity_db}' => self::clarityDb(),
            '{org}' => (string) $orgId,
            '{f}' => (string) $utcStart->timestamp,
            '{t}' => (string) $utcEnd->timestamp,
            '{d_from}' => "toDate('".$utcStart->toDateString()."')",
            '{d_to}' => "toDate('".$utcEnd->toDateString()."')",
            '{event_type}' => "'".BillingEventType::CASE_CLAIM->value."'",
        ]);
    }

    private static function run(string $sql): array
    {
        // No try/catch: a failure must propagate rather than become an indistinguishable 0,
        // and the framework handler reports the uncaught exception exactly once.
        return DB::connection('clarity_clickhouse')->select($sql);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{all_claims: int, successful: int, deduped: int}
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'all_claims' => (int) ($row['all_claims'] ?? 0),
            'successful' => (int) ($row['successful'] ?? 0),
            'deduped' => (int) ($row['deduped'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{deduped: int, pre_claim: int}
     */
    private static function normalizePreClaimRow(array $row): array
    {
        return [
            'deduped' => (int) ($row['deduped'] ?? 0),
            'pre_claim' => (int) ($row['pre_claim'] ?? 0),
        ];
    }

    private static function sql(bool $bucketed, bool $withPreClaimScans = false): string
    {
        $triple = BillingClaimDedup::tripleSql();
        $select = $bucketed
            ? "bucket, count() AS all_claims, countIf(success) AS successful, uniqExactIf({$triple}, success AND vin != '') AS deduped"
            : "count() AS all_claims, countIf(success) AS successful, uniqExactIf({$triple}, success AND vin != '') AS deduped";
        $groupBy = $bucketed ? 'GROUP BY bucket ORDER BY bucket' : '';

        // DEV-2465 needs the claim's own hotlist_id and raw epoch ts to join against
        // clarity.hits_cold; the base funnel above never needs either, so they're added
        // ONLY on this branch to keep window()/monthlySeries()'s SQL byte-identical.
        $claimsExtra = $withPreClaimScans
            ? ",\n                    hotlist_id                                                AS hotlist_id,\n                    ts                                                        AS ts"
            : '';

        $base = <<<SQL
            WITH
            claims AS (
                SELECT
                    org_id                                                   AS forwarder_org_id,
                    agent_org_id                                             AS agent_org_id,
                    vin                                                      AS vin,
                    success                                                  AS success,
                    toStartOfMonth(event_date)                               AS bucket{$claimsExtra}
                FROM {clarity_db}.billing_claim_events_v
                WHERE is_internal = 0
                  AND event_type = {event_type}
                  AND ts BETWEEN {f} AND {t}
                  AND event_date BETWEEN {d_from} AND {d_to}
                  AND ({org} = 0 OR org_id = {org} OR agent_org_id = {org})
            )
            SQL;

        if (! $withPreClaimScans) {
            return <<<SQL
                {$base}
                SELECT {$select}
                FROM claims
                {$groupBy}
                SQL;
        }

        return self::preClaimSql($base, $bucketed);
    }

    /**
     * DEV-2465 numerator on top of the shared claims/kept CTEs above: one hit-before-claim
     * flag per (hotlist_id, trip), then uniqExact'd on the triple so an order pair sharing a
     * triple can't push the rate over 100%.
     *
     * Review round 3 (S1) deleted the `stats` CTE that used to sit alongside this: it computed
     * all_claims/successful, which NOTHING downstream reads (rate() only ever reads
     * deduped/pre_claim), for a whole extra scan of the base table. `claims` is referenced
     * from `oc`, and `oc` itself is referenced TWICE: once by
     * `flagged` (the deduped/pre_claim aggregate) and once by `h`'s own pruning subquery
     * (`hotlist_id IN (SELECT ... FROM oc)`, restored in the same round -- S4). That prune is
     * worth the second `oc` expansion: it trades +40.5M read_rows for a LEFT JOIN build side
     * pruned to only the hotlist ids this window actually claimed -- measured peak memory 233.9
     * MiB vs 499.6 MiB unpruned (x29 entitled orgs at ONE cold key each: ~6.6 GiB vs ~14.2 GiB), because the
     * join build side can't spill (max_bytes_in_join/join_algorithm are unset on
     * clarity_clickhouse).
     */
    private static function preClaimSql(string $base, bool $bucketed): string
    {
        $ocBucket = $bucketed ? ', bucket' : '';
        $flaggedBucket = $bucketed ? 'oc.bucket AS bucket,' : '';
        $triple = BillingClaimDedup::tripleSql();

        $tail = <<<SQL
            ,
            oc AS (
                SELECT hotlist_id, {$triple} AS trip, min(ts) AS claim_ts{$ocBucket}
                FROM claims
                WHERE success AND vin != ''
                GROUP BY hotlist_id, trip{$ocBucket}
            ),
            -- FINAL removed (review round 2, R4) -- measured answer-neutral against production,
            -- both with and without: 11,573 / 11,975 identically -- and it suppresses PREWHERE
            -- movement here (optimize_move_to_prewhere_if_final defaults to 0), forcing a
            -- merge-on-read across every partition for no benefit measured on this population.
            --
            -- CORRECTED (review round 3, S3): _peerdb_is_deleted = 0 WITHOUT FINAL excludes the
            -- tombstone VERSION but leaves a prior live version in place, so a deleted-but-
            -- unmerged hit can still contribute to min(scanned_at) -- min() is duplicate-
            -- idempotent, not deletion-idempotent. Accepted rather than fixed: the error can
            -- only ever inflate the numerator, and adding FINAL back buys nothing measured.
            --
            -- scanned_at > toDateTime(0): hits_cold.scanned_at is non-Nullable with no DDL
            -- default, but _peerdb_scan_synced_at DEFAULTs to toDateTime('1970-01-01') -- a row
            -- synced before its scan facet lands can read scanned_at at that 1970 default, which
            -- precedes every claim and makes min() return it. Same failure mode
            -- h.hotlist_id != 0 guards below, arriving through a door that guard can't cover
            -- because the join genuinely matches. One line, unconditionally safe: a 1970 scan is
            -- not a real sighting.
            --
            -- Pruned via `oc` (review round 3, S4): restores the hotlist_id IN (...) narrowing
            -- review round 2 (R1) had removed to cut `kept`'s expansion from three to two.
            -- Affordable again now that `stats` is gone (S1) -- see this method's docblock.
            -- `WHERE hotlist_id != 0` in the IN list: a hotlist-less claim has no order to
            -- find hits for, so pushing 0 into the list only widens the scan.
            --
            -- No kit_org_id filter: ANY Insight camera counts toward pre_claim, not only the
            -- viewing org's own. Operator decision (review round 2, R6) -- the approved metric
            -- wording is "seen by OUR cameras first", where "our" means Insight's network, and
            -- that fleet-wide reading is the only one that reproduces the 96.6% AC-1 pin.
            h AS (
                SELECT hotlist_id, min(scanned_at) AS first_scan
                FROM {clarity_db}.hits_cold
                WHERE _peerdb_is_deleted = 0
                  AND scanned_at > toDateTime(0)
                  AND hotlist_id IN (SELECT DISTINCT hotlist_id FROM oc WHERE hotlist_id != 0)
                GROUP BY hotlist_id
            ),
            flagged AS (
                SELECT
                    oc.trip AS trip,
                    {$flaggedBucket}
                    -- join_use_nulls defaults to 0: a LEFT JOIN miss fills the non-Nullable
                    -- first_scan with 1970-01-01, which precedes every claim. h.hotlist_id != 0
                    -- is the guard, and a test pins it (removing it flips a hitless claim to
                    -- pre-claim). It is also sufficient on its own: the join is an equality on
                    -- hotlist_id, so a match implies h.hotlist_id == oc.hotlist_id and a miss
                    -- fills 0 -- either way a hotlist-less claim (oc.hotlist_id = 0) fails
                    -- this predicate. A separate `oc.hotlist_id != 0` term was carried here
                    -- for two rounds and was unconditionally dead; mutation testing proved it
                    -- could be deleted with every test still green, so it was (round 4, T3).
                    (h.hotlist_id != 0 AND h.first_scan < toDateTime(oc.claim_ts)) AS pre_claim
                FROM oc
                LEFT JOIN h ON h.hotlist_id = oc.hotlist_id
            )
            SQL;

        if (! $bucketed) {
            return <<<SQL
                {$base}
                {$tail},
                pre AS (
                    SELECT uniqExact(trip) AS deduped, uniqExactIf(trip, pre_claim) AS pre_claim FROM flagged
                )
                SELECT pre.deduped AS deduped, pre.pre_claim AS pre_claim
                FROM pre
                SQL;
        }

        return <<<SQL
            {$base}
            {$tail},
            pre AS (
                SELECT bucket, uniqExact(trip) AS deduped, uniqExactIf(trip, pre_claim) AS pre_claim
                FROM flagged
                GROUP BY bucket
            )
            SELECT pre.bucket AS bucket, pre.deduped AS deduped, pre.pre_claim AS pre_claim
            FROM pre
            ORDER BY pre.bucket
            SQL;
    }

    /**
     * Database prefix for the view and the clarity.hits_cold read. Mirrors
     * ClickhouseQuery::clarityDb() -- this class deliberately isn't a subclass (see the
     * class docblock), so the helper is duplicated rather than inherited.
     */
    private static function clarityDb(): string
    {
        return config('database.connections.clarity_clickhouse.driver') === 'pgsql'
            ? 'clarity'
            : config('database.connections.clarity_clickhouse.database');
    }
}
