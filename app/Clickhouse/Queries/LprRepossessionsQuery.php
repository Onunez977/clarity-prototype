<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\BillingEventType;

/**
 * Single-pass ClickHouse repossessions funnel (recoveries-leg measure): how many billed case
 * claims (`deduped`, reusing UniqueLprCaseClaimsQuery's validated triple) went
 * on to a "recovered" (`status = 3`) hotlist transition within the backyard
 * repo window (`db_repos`). Deliberately NOT a ClickhouseQuery subclass, for
 * the same reason as its sibling: that base's query() swallows Throwable and
 * returns [] in both production and testing, but a genuine zero is a normal
 * reading here (q10/q11: 25 of 29 entitled orgs were non-zero in Jul 2026, so 4 legitimately
 * read 0 that month; Aug 2026 measured 29 of 29 non-zero) -- a
 * failed query must surface rather than render indistinguishably as 0.
 *
 * SCOPE of that guarantee (review round 14, lane 2): it holds on the SYNCHRONOUS path -- a total
 * miss or a hard-expired key, which is where a fabricated 0 would be written. It does NOT hold on
 * Cache::flexible's deferred stale-refresh, because Laravel invokes deferred callbacks through
 * DeferredCallbackCollection::invokeWhen(), which wraps each one in rescue(). A ClickHouse failure
 * there is report()ed and swallowed and the card keeps serving the STALE value until the hard TTL.
 * That is a better outcome than a fabricated 0, but it is not the same guarantee, and the class
 * should not claim it unconditionally.
 *
 * SQL body is the recoveries-leg measure-org-monthly packet
 * (production-validated, reconciled against Brek's Repo Report), restructured
 * to read the `deduped` CTE ONCE. The measured SQL references it three times
 * (`pairs`, `floor_date`, the final FROM). okf/concepts/billing-events.md
 * measured THREE references to zod.public_billing_events at exactly 3.0x the
 * read_rows of a single-pass read (119,848,324 vs 39,949,441). DEV-2659 keeps
 * that single pass: one reference to billing_claim_events_v, and the outer
 * SELECT reads `deduped` once.
 *
 * The recoveries timestamp is `clarity.hotlist.first_repossessed_at`
 * (DEV-2660): the MV's `minIf(created_at, new_status = 3)`, one value per
 * hotlist. That column is the status-3 transition instant, so variant B is
 * viable. `clarity.hotlist.repo_date` is not that timestamp and stays
 * rejected -- it disagreed with the status-log transition on 1,588 / 6,770
 * counted pairs (23%). That comparison is closed.
 *
 * `uniqExactIf((triple), toDate(ch.first_repossessed_at, 'UTC') >=
 * d.claim_date - 7)` keeps the backyard window. The `- 7` is deliberate.
 *
 * `{floor}` (the UTC window start date minus 7 days, computed once in PHP)
 * stays on the clarity subquery: `toDate(first_repossessed_at, 'UTC') >=
 * {floor}`. It was never a sort-key prune (`public_hotlist_status_log` is
 * `ORDER BY id`). It shrinks the hash build side. It is <= `min(claim_date)
 * - 7` for every claim in the window, so it cannot change the answer.
 * Preflight #25 kept it: floor-off blows memory ~12.2GB vs ~234MB.
 *
 * Preflight #25 (Jul 2026, box-side fleet RO measure, 2026-09-28T14:23:53Z):
 * AC-1 agreement 100% (9149/9149), AC-3 deduped 11975/11975 and db_repos
 * 9149/9149 (Δ0), AC-4 read_bytes −35%. `FINAL` stays off `clarity.hotlist`:
 * db_repos was identical and FINAL costs more bytes and memory.
 *
 * The (org, vin) match stays `zod.public_hotlist FINAL`, `h.vin`
 * un-normalised (zod hotlist match / DEV-2660).
 *
 * Hotlist CDC stays asymmetric (driver-verification.md §7, PG-028): FINAL +
 * _peerdb_is_deleted = 0 on public_hotlist (raw over-counts it by 13.3%).
 * The clarity.hotlist read is `_peerdb_is_deleted = 0` WITHOUT FINAL.
 *
 * DEV-2801 Gate 1 Cloud RO measure (Jul 2026 UTC, org=0; 2026-10-06 ~21:32 CT):
 * candidates A (single clarity.hotlist, no FINAL) and B (same WITH FINAL) both
 * met 100% agreement and db_repos/deduped Δ0 vs the post-2660 dual-join
 * baseline (live re-measure db_repos 9159; 9149 is historical DEV-2660 only).
 * A is blocked by m1 stale_status3_ids=12 (mutable-status CDC without FINAL).
 * B is blocked by read_bytes > baseline (6.67GB > 6.32GB). Therefore KEEP the
 * dual-join. A future collapse needs a fresh measure. Artifact:
 * `docs/investigations/DEV-2801/cloud-parity-jul-2026.md`.
 *
 * DEV-2659 billing leg: `{clarity_db}.billing_claim_events_v`, alias `org_id`
 * as `forwarder_org_id`, exclusion `is_internal = 0`. This class does not read `clarity.orgs`.
 * Failures propagate from the clarity_clickhouse select.
 *
 * `is_internal` is frozen when `clarity.billing_claim_events_mv` refreshes,
 * from `zod.public_orgs FINAL` across all org types. A later org rename does
 * not rewrite historical rows. API-key changes in that MV re-read only an
 * 8-day window (`today() - 8`); older key moves are not backfilled by the
 * hourly refresh. The view does not project `_peerdb_is_deleted`, so a
 * tombstone predicate is unexpressible on `_v`. Tombstones are dropped inside
 * the view (`FINAL` where `_peerdb_is_deleted = 0`).
 *
 * `event_date BETWEEN` uses PHP `toDate('Y-m-d')` literals, the same shape as
 * `{floor}` below, so the leading sort key can prune. `ts BETWEEN` stays.
 *
 * AC-5: the billing leg reads billing_claim_events_v. The zod hotlist match
 * and the first_repossessed_at recoveries leg (DEV-2660) stay on their own
 * sources. The class-neutral shared keys stay.
 */
class LprRepossessionsQuery
{
    /**
     * Single-window scalar. $orgId of 0 (or null) means platform-wide;
     * otherwise the org is counted on EITHER the forwarder or the agent side.
     *
     * @return array{deduped: int, db_repos: int}
     */
    public static function window(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = self::run(self::windowSql($orgId, $start, $end));

        return self::normalizeRow($rows[0] ?? []);
    }

    public static function windowSql(?int $orgId, CarbonInterface $start, CarbonInterface $end): string
    {
        return self::buildSql(false, $orgId ?? 0, $start, $end);
    }

    /**
     * 13-month, UTC `toStartOfMonth`-bucketed series. One round trip -- the
     * span. The view's table is ORDER BY (event_date, id); the PHP date bound
     * can prune. `ts BETWEEN` still applies.
     *
     * @return array<string, array{deduped: int, db_repos: int}> keyed by 'Y-m-01'
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
     * The 13-month window arithmetic lives here ONLY -- monthlySeries() reads it back through
     * this method so the emitted SQL and the executed SQL cannot drift.
     */
    public static function monthlySeriesSql(?int $orgId, CarbonInterface $through): string
    {
        $end = $through->clone()->utc()->endOfMonth();
        $start = $end->clone()->subMonthsNoOverflow(12)->startOfMonth();

        return self::buildSql(true, $orgId ?? 0, $start, $end);
    }

    /**
     * One row per `(forwarder_org_id, agent_org_id, vin)` that `uniqExactIf` counts as `db_repos`.
     * The statement projects the export columns; decoration joins sit after GROUP BY/HAVING.
     * Does not read or write the shared monthly-series or window cache keys.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        return self::run(self::rowsSql($orgId, $start, $end));
    }

    public static function rowsSql(?int $orgId, CarbonInterface $start, CarbonInterface $end): string
    {
        return self::substitute(self::listingSql(), $orgId ?? 0, $start, $end);
    }

    private static function buildSql(bool $bucketed, int $orgId, CarbonInterface $start, CarbonInterface $end): string
    {
        return self::substitute(self::sql($bucketed), $orgId, $start, $end);
    }

    private static function substitute(string $sql, int $orgId, CarbonInterface $start, CarbonInterface $end): string
    {
        $utcStart = $start->clone()->utc();
        $utcEnd = $end->clone()->utc();

        return strtr($sql, [
            '{db}' => self::zodDb(),
            '{clarity_db}' => self::clarityDb(),
            '{org}' => (string) $orgId,
            '{f}' => (string) $utcStart->timestamp,
            '{t}' => (string) $utcEnd->timestamp,
            '{d_from}' => "toDate('".$utcStart->toDateString()."')",
            '{d_to}' => "toDate('".$utcEnd->toDateString()."')",
            '{event_type}' => "'".BillingEventType::CASE_CLAIM->value."'",
            '{floor}' => "toDate('".$utcStart->clone()->subDays(7)->toDateString()."')",
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
     * @return array{deduped: int, db_repos: int}
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'deduped' => (int) ($row['deduped'] ?? 0),
            'db_repos' => (int) ($row['db_repos'] ?? 0),
        ];
    }

    /**
     * Backyard predicate shared by the aggregate `uniqExactIf` and the listing `countIf`/`argMinIf`.
     * The aggregate SELECT interpolates this string so the rendered SQL stays byte-identical.
     */
    private const REPO_PREDICATE = "toDate(ch.first_repossessed_at, 'UTC') >= d.claim_date - 7";

    private static function sql(bool $bucketed): string
    {
        $select = $bucketed
            ? 'd.bucket AS bucket, uniqExact((d.forwarder_org_id, d.agent_org_id, d.vin)) AS deduped, uniqExactIf((d.forwarder_org_id, d.agent_org_id, d.vin), '.self::REPO_PREDICATE.') AS db_repos'
            : 'uniqExact((d.forwarder_org_id, d.agent_org_id, d.vin)) AS deduped, uniqExactIf((d.forwarder_org_id, d.agent_org_id, d.vin), '.self::REPO_PREDICATE.') AS db_repos';
        $groupBy = $bucketed ? 'GROUP BY d.bucket ORDER BY d.bucket' : '';

        return self::aggregateSql($select, $groupBy);
    }

    /**
     * Listing of the `db_repos` triple. Groups by the triple only (not the month bucket).
     * Plate, RDN case number, and Claimed By join after HAVING so they cannot change the set.
     * Repo Agent Name is a literal blank. The string `id` is the triple, not a hash.
     */
    private static function listingSql(): string
    {
        $inner = 'SELECT d.forwarder_org_id AS forwarder_org_id, d.agent_org_id AS agent_org_id, d.vin AS vin, argMinIf(h.id, (ch.first_repossessed_at, h.id), '.self::REPO_PREDICATE.') AS hotlist_id'."\n"
            .'FROM deduped AS d'."\n"
            .self::listingRecoveryJoins()
            .'GROUP BY d.forwarder_org_id, d.agent_org_id, d.vin'."\n"
            .'HAVING countIf('.self::REPO_PREDICATE.') > 0';

        $outer = "concat(toString(g.forwarder_org_id),'|',toString(g.agent_org_id),'|',g.vin) AS id, g.vin AS vin, hp.plate AS plate, hj.case_number AS case_number, o.name AS claimed_by, '' AS repo_agent_name";

        return self::withClause()
            .'SELECT '.$outer."\n"
            ."FROM (\n"
            .$inner."\n"
            .") AS g\n"
            .self::decorationJoins();
    }

    private static function aggregateSql(string $select, string $groupBy): string
    {
        $sql = self::withClause().'SELECT '.$select."\nFROM deduped AS d\n".self::recoveryJoins();

        if ($groupBy !== '') {
            $sql .= $groupBy;
        }

        return $sql;
    }

    private static function withClause(): string
    {
        $deduped = BillingClaimDedup::dedupedCteSql();

        return <<<SQL
WITH
claims AS (
    SELECT
        org_id                                                   AS forwarder_org_id,
        agent_org_id                                             AS agent_org_id,
        vin                                                      AS vin,
        success                                                  AS success,
        event_date                                               AS claim_date,
        toStartOfMonth(event_date)                               AS bucket
    FROM {clarity_db}.billing_claim_events_v
    WHERE is_internal = 0
      AND event_type = {event_type}
      AND ts BETWEEN {f} AND {t}
      AND event_date BETWEEN {d_from} AND {d_to}
      AND ({org} = 0 OR org_id = {org} OR agent_org_id = {org})
),
deduped AS (
    {$deduped}
)

SQL;
    }

    private static function recoveryJoins(): string
    {
        return <<<'SQL'
LEFT JOIN (
    SELECT id, org_id, vin FROM {db}.public_hotlist FINAL
    WHERE _peerdb_is_deleted = 0 AND status = 3
) AS h ON h.org_id = d.agent_org_id AND h.vin = d.vin AND d.agent_org_id != 0
-- `AND h.id != 0` below is the SAME sentinel guard as `d.agent_org_id != 0` above, for
-- the chained join. clarity_clickhouse sets no join_use_nulls, so ClickHouse's default 0
-- applies and an UNMATCHED h yields type defaults, not NULL -- h.id becomes 0, which then
-- matches a clarity.hotlist row with id = 0 and counts a triple that has no status-3
-- hotlist at all. No FINAL: preflight #25 db_repos was identical and FINAL costs more.
LEFT JOIN (
    SELECT id, first_repossessed_at FROM {clarity_db}.hotlist
    WHERE _peerdb_is_deleted = 0
      AND toDate(first_repossessed_at, 'UTC') >= {floor}
) AS ch ON ch.id = h.id AND h.id != 0

SQL;
    }

    /**
     * Shared join text includes a SQL comment whose apostrophe breaks the ClickHouse
     * client's `?` binder. The listing is the statement ExportCsv re-runs with bindings,
     * so those comment lines are removed here. The aggregate still uses recoveryJoins().
     */
    private static function listingRecoveryJoins(): string
    {
        $joins = preg_replace('/--[^\n]*/', '', self::recoveryJoins());

        return is_string($joins) ? $joins : self::recoveryJoins();
    }

    private static function decorationJoins(): string
    {
        return <<<'SQL'
LEFT JOIN (
    SELECT id, plate FROM {db}.public_hotlist FINAL
    WHERE _peerdb_is_deleted = 0
) AS hp ON hp.id = g.hotlist_id AND g.hotlist_id != 0
LEFT JOIN (
    SELECT hotlist_id, case_number FROM {db}.hotlist_json_denormalized FINAL
    WHERE _peerdb_is_deleted = 0
) AS hj ON hj.hotlist_id = g.hotlist_id AND g.hotlist_id != 0
LEFT JOIN (
    SELECT id, name FROM {db}.public_orgs FINAL
    WHERE _peerdb_is_deleted = 0
) AS o ON o.id = g.agent_org_id AND g.agent_org_id != 0

SQL;
    }

    /**
     * Class-neutral cache key for the 13-month series, so every card reading this query
     * shares ONE cached result instead of each maintaining a class-prefixed copy.
     *
     * DashboardWidget::buildWidgetCacheKey() prefixes class_basename(), which makes a key
     * unreadable from a sibling card -- the trap okf/concepts/company-metrics-dashboard.md
     * records. Two cards now read this query (LprRepossessions and
     * UniqueScansToLprRepossession) with byte-identical arguments, and without a shared key
     * they ran it TWICE per render: 584M rows / 2.79 GB peak / 33.7s tail, duplicated for no
     * correctness gain. Mirrors ScanPlateStatsBatchQuery::sharedMonthCacheKey().
     *
     * Deliberately carries NEITHER a viewer day NOR a `:tz=` segment. The value is
     * monthlySeries($orgId, now('UTC')) -- identical for every viewer of an org, whatever
     * their zone. LprRepossessions' round-13 review established that forking it per zone
     * multiplies the leg for nothing; forking it per CLASS is the same mistake.
     *
     * PAYLOAD CONTRACT -- the clause whose absence broke this once already. This key carries
     * EXACTLY ONE shape: monthlySeries()'s `array<'Y-m-01', {deduped, db_repos}>`, verbatim.
     * A caller that stores anything else (a selected stage, an int total) corrupts every other
     * reader SILENTLY -- `$int['db_repos'] ?? 0` is 0 and `(int) $array` is 1, neither warns.
     * If the shape must change, bump the `-v1` suffix; do not overload the key. This is why the
     * sibling WINDOW leg is deliberately NOT shared: the two cards need different shapes there.
     *
     * CONTRACT: callers MUST also use the same [fresh, hard] TTL pair and go through
     * heldFlexible(), so the Cache::flexible companion key and the cold mutex are shared too.
     * A caller writing this key with a plain cache()->remember() would omit the companion key
     * and make every flexible() reader treat it as a miss.
     */
    public static function sharedMonthlySeriesCacheKey(int $orgId, string $monthKey): string
    {
        return "lpr-repossessions-funnel:monthly-series-v1:org={$orgId}:{$monthKey}";
    }

    /**
     * Class-neutral cache key for ONE resolved window of the funnel.
     *
     * ==> PAYLOAD CONTRACT: this key carries an `int` -- the `db_repos` stage, ALREADY SELECTED.
     * Not the {deduped, db_repos} array. <==
     *
     * That clause is the whole point. An earlier review round RN2 shared a window key where one card stored an
     * int and the other stored the array; both mis-reads are silent (`$int['db_repos'] ?? 0` is
     * 0, `(int) $array` is 1) and the two cards rendered each other's garbage. RN3 reacted by
     * un-sharing the key entirely, on the stated grounds that the second card "needs the whole
     * array" -- which was FALSE: it reads only `db_repos`. That un-share therefore bought
     * nothing and ran this 584M-row / 2.79 GB / 33.7s-tail query TWICE per cold render on the
     * today/last_7d arms. RN4 caught it. The fix is one key carrying one shape, which is what
     * the sibling ScanPlateStatsBatchQuery keys have always done.
     *
     * If a caller ever needs `deduped` as well, do NOT widen this payload -- add a second key,
     * or bump the `-v1` suffix and migrate every writer together.
     *
     * Unlike the series key this DOES carry the viewer day and timezone: 'today'/'last_7d' end
     * at the viewer's endOfDay, so two zones are two different windows.
     *
     * CONTRACT: callers MUST also use the same [fresh, hard] TTL pair and go through
     * heldFlexible(), so the Cache::flexible companion key and the cold mutex are shared too.
     */
    public static function sharedWindowCacheKey(int $orgId, string $dateType, string $day, string $timezone): string
    {
        $key = "lpr-repossessions-funnel:window-v1:org={$orgId}:{$dateType}:{$day}:tz={$timezone}";

        return str_replace(' ', '', $key);
    }

    /**
     * Database prefix for the billing view. Mirrors ClickhouseQuery::clarityDb().
     */
    private static function clarityDb(): string
    {
        return config('database.connections.clarity_clickhouse.driver') === 'pgsql'
            ? 'clarity'
            : config('database.connections.clarity_clickhouse.database');
    }

    /**
     * Database prefix for the cross-database zod.* CDC reads this query makes
     * from the clarity connection. Mirrors ClickhouseQuery::zodDb() so this
     * resolves correctly under both prod (`zod`) and local-CH QA naming.
     * The (org, vin) hotlist match stays on zod (DEV-2660).
     */
    private static function zodDb(): string
    {
        return config('database.connections.clarity_clickhouse.driver') === 'pgsql'
            ? 'zod'
            : config('database.connections.public_clickhouse.database');
    }
}
