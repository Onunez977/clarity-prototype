<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use App\Filament\Widgets\Dashboard\Concerns\ResolvesDashboardCacheTtl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `clarity.scans_cold` is the only source with a plate column (`ocr`); no pre-aggregate
 * answers period-distinct plates (DEV-2466 sec 4/8), so this covers the full window from
 * `scans_cold` alone.
 *
 * No `FINAL`. `ocr` is immutable across a scan's CDC versions, so `uniqExact(ocr)`
 * collapses CDC duplicates on its own -- unlike `hits_cold`, where a mutable sort-key
 * column makes `FINAL` load-bearing. Skipping it here is measured, not assumed: identical
 * answers with vs without `FINAL`, at +97% server time / +149% bytes WITH it (DEV-2466
 * sec 5). Keep `_peerdb_is_deleted = 0` -- it is nearly free and guards the delete case.
 *
 * Org scoping is `org_id` directly, never resolved to kit ids and filtered on `kit_id`:
 * `org_id` leads `scans_cold`'s sort key, `kit_id` is third, and routing through it costs
 * 6.7x the rows and 3.8x the server time for an identical answer (DEV-2466 sec 1c).
 *
 * `scans_cold`, never `scans_hot` -- the hot table carries 5x the parts, so `FINAL`'s
 * merge work makes it 3.3x SLOWER despite fewer rows (DEV-2466 sec 6). That is the
 * opposite of `ScanCount::getModelQueryForDates()`'s current-month routing, which is a
 * known latent bug (`Scan::hot()::query()` discards `setTable`, DEV-2324) this class does
 * not inherit.
 *
 * handle() carries no kit dimension: the 13-month series x per-kit rollup was never
 * measured and would multiply the group count from 13 to roughly 13 x kits against a
 * 2.18B-row table (DEV-2466 sec 4 vs sec 7). Only handleRange() (a single window) carries
 * `GROUP BY kit_id WITH ROLLUP`, whose `kit_id = 0` row is the org total -- one scan
 * answers both this card's headline and DEV-2468's leaderboard (DEV-2466 sec 7).
 *
 * THE ZONE INVARIANT: whatever zone minted the caller's naive `start`/`end` strings MUST
 * be the zone passed in `timezone`. Both entry points convert the bounds with it, so a
 * mismatch silently shifts the window.
 *
 * handle() carries one EXTRA requirement, because its grouping expression IS the bucket
 * key: the zone must also be one ClickHouse accepts and one PHP can construct, so
 * handle() puts it through safeTimezone() before both the conversion and the grouping.
 * A legacy alias this PHP can no longer construct is remapped to its canonical IANA id
 * there; it is never returned raw and never replaced with UTC. Callers of handle() should
 * mint their bounds with the sanitised zone too (UniqueScanCounts does), which makes that
 * call idempotent and keeps window and buckets aligned by construction.
 *
 * handleRange() has NO such requirement -- it interpolates no timezone at all -- so it uses
 * the caller's zone verbatim. Normalising it here would be actively wrong: its DAY-granularity
 * callers are the Today / Last 7d filter path, where the user means their real local day. An
 * earlier revision normalised it anyway and shifted those filters by the zone offset -- up to
 * 8h for PST. The MONTH-granularity arms (This Month / Last Month) are the opposite case and
 * DO pass the sanitised zone, because the month they name has to be the month the 13-month
 * bucket series names; the caller decides per granularity, not this method.
 *
 * TIMEZONE: every caller (UniqueScanCounts) builds `start`/`end` as NAIVE wall-clock
 * strings in the VIEWER's timezone (`now(tz: $timezone)`/`resolveCardRange()`), the same
 * way `App\Clickhouse\Models\Scan::scopeBetween()`'s callers do. `scanned_at` is UTC, so
 * both entry points require an explicit `timezone` argument and convert the bounds with
 * `Carbon::parse($v, $tz)->utc()` before they reach SQL -- mirroring `scopeBetween()`
 * exactly (`okf/models/clickhouse/scans_cold.md`). `timezone` has no default: a caller
 * that omits it fails loudly (an undefined-array-key warning, then an empty string that
 * safeTimezone() resolves to UTC) rather than silently misreading a local string as UTC.
 *
 * AC-5: a cold start is N concurrent `scans_cold` reads, one per org that misses
 * the cache. DEV-2466 measured 60,738,996 rows / 2.44 GB for one copy. Sharing
 * the param-keyed month and range entries is what keeps the same org, window,
 * and timezone from paying that scan once per card.
 */
class ScanPlateStatsBatchQuery extends ClickhouseQuery
{
    use ResolvesDashboardCacheTtl;

    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * Closed shared-month ints sit behind the multi-GB scans_cold scan. While
     * impersonating, the ladder is 10 seconds; this floor keeps that arm at 10
     * minutes. It does not lengthen a settle-window month or shorten a year.
     */
    public const IMPERSONATED_PAST_MONTH_TTL_MINUTES = 10;

    /**
     * The shared FROM + WHERE for both entry points, so the org/date/tombstone filters
     * can never drift between the two. `$start`/`$end` must already be UTC by the time
     * they reach here -- both callers convert before calling this.
     */
    private static function fromAndWhere(string $clarityDb, int $orgId, string $start, string $end): string
    {
        return <<<SQL
            FROM {$clarityDb}.scans_cold
            WHERE org_id = {$orgId}
              AND scanned_at >= toDateTime('{$start}')
              AND scanned_at <= toDateTime('{$end}')
              AND _peerdb_is_deleted = 0
            SQL;
    }

    /**
     * Converts a caller-supplied local wall-clock string to a UTC `Y-m-d H:i:s` string,
     * exactly as `Scan::scopeBetween()` does. Validated against the DATETIME_PATTERN
     * allowlist AFTER conversion, so the interpolated value is always well-formed
     * regardless of what format the caller's Carbon-produced string had.
     */
    private static function toUtcDatetime(string $value, string $timezone, string $field): string
    {
        $utc = Carbon::parse($value, $timezone)->utc()->toDateTimeString();

        self::assertDatetime($utc, $field);

        return $utc;
    }

    /**
     * Fleet legacy aliases still stored on live viewer zones, keyed to the canonical
     * IANA id used when this PHP can no longer construct the alias.
     *
     * PHP 8.4.25 may drop these from `timezone_identifiers_list(ALL_WITH_BC)` and from
     * constructability (`new \DateTimeZone($alias)` throws `DateInvalidTimeZoneException`).
     * When the alias still constructs, {@see self::safeTimezone()} returns it (identity-keep).
     * When it does not, the canonical id preserves that day-boundary
     * contract without throwing and without falling back to UTC.
     *
     * @var array<string, string>
     */
    private const LEGACY_FLEET_TIMEZONE_ALIASES = [
        'US/Central' => 'America/Chicago',
        'US/Eastern' => 'America/New_York',
        'US/Pacific' => 'America/Los_Angeles',
        'Asia/Calcutta' => 'Asia/Kolkata',
    ];

    /**
     * Allowlist for the IANA timezone identifier before it is interpolated into
     * `toTimeZone(scanned_at, '...')`, and used for the bound conversion.
     *
     * Falls back to UTC; it does NOT throw. That is the contract
     * {@see KitScanPathsQuery::safeTimezone()} records (DEV-2441) and this method
     * deliberately matches it. An earlier version of this class threw instead, and RN3
     * review caught what that costs: `User::timezone` reads `users.dti`, a json column
     * written straight from the request by `UserDtiController` with no validation, and
     * `CST`, `PST` and `+05:00` all construct a valid `DateTimeZone` while being absent
     * from this list. `CompanyMetricsOverview::getStats()` has no per-card guard, so a
     * throw here took down all ten cards for such a user -- strictly worse than the naive
     * -timezone bug the conversion was added to fix.
     *
     * `ALL_WITH_BC`, not the canonical-only default: the default drops legacy aliases
     * still in live use in this fleet -- `US/Central`, `US/Eastern`, `US/Pacific`,
     * `Asia/Calcutta` -- and silently falling those back to UTC is a 5-6 hour
     * day-boundary shift, which is the DEV-2441 defect itself.
     *
     * PHP 8.4.25 may drop those BC aliases from `timezone_identifiers_list(ALL_WITH_BC)`
     * AND from constructability (8.4.23 still constructs them; older builds reported
     * 598 ids). Returning the alias anyway throws inside Carbon (`toUtcDatetime()` /
     * `now(tz:)`), and `CompanyMetricsOverview::getStats()` has no per-card try, so the
     * grid 500s. When `new \DateTimeZone($alias)` succeeds, return the alias (identity-keep).
     * On `\DateInvalidTimeZoneException` — or `\Exception` on older PHP —
     * return the canonical id (`US/Central` → `America/Chicago`, `US/Eastern` →
     * `America/New_York`, `US/Pacific` → `America/Los_Angeles`, `Asia/Calcutta` →
     * `Asia/Kolkata`). Never UTC for those four. Never throw from this method. Do not
     * probe with `timezone_open` (it warns). `CST`, `PST`, `+05:00`, and garbage still
     * fall back to UTC and never throw.
     *
     * SCOPE: handle()'s path only -- its bound conversion AND its SQL grouping, which must
     * agree. Deliberately NOT applied to handleRange() (no SQL zone, and its filter callers
     * need the real local day) nor forced inside sharedRangeCacheKey() (which must describe
     * whatever zone its caller actually queried with).
     *
     * That single-value rule resolves a genuine forced choice, and BOTH of its horns were
     * shipped and reviewed before this shape settled. For a zone ClickHouse cannot accept,
     * the bounds and the grouping cannot both be exact:
     *
     *   - ONE value for both (this code). For an unlisted zone the caller's naive
     *     `2026-09-01 00:00:00` is read as UTC rather than as 06:00 UTC in CST, so the
     *     window sits up to ~6h off local. Every scan still lands in exactly one bucket
     *     and the buckets tile the window exactly. RN4 review flagged the offset.
     *   - RAW for bounds, allowlisted for grouping (withdrawn). The window is exact, but
     *     the grouping then emits month keys the caller's write set does not contain: for
     *     CST the last ~6h of the viewer's August keys to '2026-09' in UTC, falls outside
     *     `uncachedPastMonths()`, and is DISCARDED -- then the short August bucket
     *     persists at a one-year TTL with no self-heal. RN5 review flagged the data loss.
     *
     * Silent data loss is the worse failure, so the offset is the horn we keep. It is also
     * the convention every sibling card already ships (naive local strings against the UTC
     * column). The blast radius is small either way: every zone on `ALL_WITH_BC` resolves to
     * itself, and the four fleet aliases resolve to themselves when PHP can construct
     * them or to their canonical IANA ids when it cannot. For essentially every real
     * viewer, bounds AND grouping are exactly local and neither horn applies.
     *
     * NOT the trade `KitScanPathsQuery` makes -- do not cite it as precedent for the
     * withdrawn shape. That class applies safeTimezone() to a per-row `toDate(...)` LABEL
     * (its line 135) while its bounds use the raw zone (229-234), and no row is ever
     * discarded, because a label is not a bucket key. Here the grouping expression IS the
     * bucket key, so a mismatch drops rows.
     */
    public static function safeTimezone(string $timezone): string
    {
        if (\array_key_exists($timezone, self::LEGACY_FLEET_TIMEZONE_ALIASES)) {
            try {
                new \DateTimeZone($timezone);

                return $timezone;
            } catch (\DateInvalidTimeZoneException) {
                return self::LEGACY_FLEET_TIMEZONE_ALIASES[$timezone];
            } catch (\Exception) {
                return self::LEGACY_FLEET_TIMEZONE_ALIASES[$timezone];
            }
        }

        return \in_array($timezone, \timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            ? $timezone
            : 'UTC';
    }

    /**
     * The 13-month series, keyed by `Y-m`.
     *
     * Grouped by `toStartOfMonth(toTimeZone(scanned_at, $timezone))`, NOT raw
     * `scanned_at` -- `DashboardWidget::getGraphLabels()` labels bars in the viewer's
     * timezone, and grouping by the raw UTC column would land the last few hours of
     * every local month in the next bar. The `WHERE` clause still filters raw
     * `scanned_at` (via toUtcDatetime()'s converted bounds), so `org_id`/`scanned_at`
     * sort-key pruning is unaffected -- only the SELECT-list grouping expression changes.
     */
    public static function handleSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        // ONE zone for the bounds AND the grouping -- see safeTimezone(). Splitting them
        // lets the grouping emit a month key outside the caller's write set, silently
        // discarding the rows in the gap and caching the short bucket for a year.
        $timezone = self::safeTimezone((string) $arguments['timezone']);

        $start = self::toUtcDatetime((string) $arguments['start'], $timezone, 'start');
        $end = self::toUtcDatetime((string) $arguments['end'], $timezone, 'end');

        $fromAndWhere = self::fromAndWhere(static::clarityDb(), $orgId, $start, $end);

        return <<<SQL
            SELECT
                toStartOfMonth(toTimeZone(scanned_at, '{$timezone}')) AS month,
                uniqExact(ocr) AS unique_plates
            {$fromAndWhere}
            GROUP BY month
            ORDER BY month
            SQL;
    }

    /**
     * @return array<string, int>
     */
    public static function handle(array $arguments = []): array
    {
        $rows = DB::connection('clarity_clickhouse')->select(self::handleSql($arguments));

        $result = [];
        foreach ($rows as $row) {
            $monthKey = date('Y-m', strtotime($row['month']));
            $result[$monthKey] = (int) $row['unique_plates'];
        }

        return $result;
    }

    /**
     * A single window's per-kit deduped-plate counts, `WITH ROLLUP` so key `0`
     * carries the org total in the same pass (used for today/last_7d/this_month/last_month
     * and shared with DEV-2468's leaderboard via {@see sharedRangeCacheKey()}).
     *
     * `GROUPING(kit_id)` identifies the rollup row EXPLICITLY, and the row-to-key mapping
     * is now genuinely order-independent: the rollup row's value is captured into a local
     * during the single pass over `$rows` and assigned to `$result['totals'][0]` AFTER the
     * loop, so it always wins regardless of whether ClickHouse (which does not guarantee
     * row order without an ORDER BY) emits the rollup row first, last, or anywhere in
     * between. A real `kit_id = 0` detail row is explicitly skipped from the per-kit map --
     * it cannot be represented alongside the sentinel key, so it is dropped rather than
     * silently overwriting the org total depending on row order.
     *
     * `anyLast(...)` carries the label DEV-2468's leaderboard needs -- the column is
     * already in the rows being scanned, so this adds no extra rows read. The rollup row
     * contributes no name (`anyLast` there aggregates across every kit and is discarded).
     * Wrapped in the `if(kit_name = '', ...)` guard {@see KitScanPathsQuery::handle()} and
     * `okf/concepts/scout-clickhouse-queries.md:47` already document for this exact
     * `kit_name String DEFAULT ''` column -- without it, a kit whose rows carry the column
     * default renders a blank chart label and an empty `<b></b>` in getDescription().
     *
     * The guard wraps the AGGREGATE, not the row: `if(anyLast(kit_name) = '', ...)`, so it
     * is evaluated once per group (tens of rows) rather than once per scanned row. An
     * earlier revision placed it inside -- `anyLast(if(kit_name = '', ...))` -- which is
     * equally correct but materialises the else-branch for EVERY scanned row, because
     * ClickHouse's default `short_circuit_function_evaluation` covers only expensive or
     * throwing functions and `concat`/`toString` are neither. At the worst-case org-month
     * measured in DEV-2466 that is ~60.7M needless string constructions. `kit_id` is
     * constant within every non-rollup group (measured immutable, below), so hoisting
     * changes no answer.
     *
     * What this does NOT fix: a real kit 0 is not representable in the returned map at
     * all (its own count is discarded, not merely at risk of being overwritten). DEV-2468's
     * leaderboard inherits that limitation. It is latent, not live -- `kits.id` is a
     * Postgres serial starting at 1, so no kit id is 0 today. If one ever can be, this map
     * needs a separate total key rather than the sentinel.
     *
     * `kit_id` immutability across a scan's CDC versions is now MEASURED, not assumed:
     * DEV-2468 found 0 divergent `kit_id` across 4,951 fleet-wide duplicate-id groups (7
     * days, 101 orgs) and 0 across 1,639 for a single large org (1 month) -- the same
     * population and epistemic bar DEV-2466 sec 5 used to clear `ocr`. `FINAL` therefore
     * stays off the per-kit read too, on both axes.
     */
    public static function handleRangeSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        // The caller's zone VERBATIM -- no safeTimezone(). This method interpolates no
        // timezone into SQL, so there is nothing to make SQL-safe, and its callers need
        // different zones per MONTH-vs-DAY granularity, not per arm name:
        // resolveCurrentMonthFromCold() and BOTH of UniqueScansLeaderboard's
        // month-granularity arms ('this_month' via currentMonthWindow(), 'last_month' via
        // lastMonthWindow()) pass the SANITISED zone, so bar 13 lines up with bars 1-12 and
        // the leaderboard names the same month the sibling card displays; getRangeTotal()
        // and the leaderboard's 'today'/'last_7d' arms pass the REAL viewer zone so "Today"
        // means the viewer's actual local day. Normalising every call served only the first and
        // broke the second (DEV-2467 RN4/RN5); this method has no opinion -- the caller's
        // per-arm choice IS the invariant, see currentMonthWindow()'s docblock.
        $timezone = (string) $arguments['timezone'];

        $start = self::toUtcDatetime((string) $arguments['start'], $timezone, 'start');
        $end = self::toUtcDatetime((string) $arguments['end'], $timezone, 'end');

        $fromAndWhere = self::fromAndWhere(static::clarityDb(), $orgId, $start, $end);

        return <<<SQL
            SELECT
                kit_id,
                GROUPING(kit_id) AS is_rollup,
                if(anyLast(kit_name) = '', concat('Kit ', toString(anyLast(kit_id))), anyLast(kit_name)) AS name,
                uniqExact(ocr) AS unique_plates
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

        if (empty($rows)) {
            return ['totals' => [0 => 0], 'names' => []];
        }

        $totals = [];
        $names = [];
        $orgTotal = 0;

        foreach ($rows as $row) {
            if (((int) $row['is_rollup']) === 1) {
                $orgTotal = (int) $row['unique_plates'];

                continue;
            }

            $kitId = (int) $row['kit_id'];

            if ($kitId === 0) {
                // A real kit 0 detail row -- cannot coexist with the org-total sentinel
                // key. See the method docblock: dropped, not merged, so it can never
                // silently overwrite (or be overwritten by) the total depending on order.
                continue;
            }

            $totals[$kitId] = (int) $row['unique_plates'];
            $names[$kitId] = (string) $row['name'];
        }

        $totals[0] = $orgTotal;

        return ['totals' => $totals, 'names' => $names];
    }

    /**
     * The current-month-to-date window (start of month through end of today), cut with
     * the BUCKET zone -- the ONE formula both `UniqueScanCounts::resolveCurrentMonthFromCold()`
     * and `UniqueScansLeaderboard::resolveRangeAndTimezone()`'s `'this_month'` arm call, so
     * the two cards cannot mint different bounds for the same window (DEV-2468 S-A: an
     * earlier revision re-derived this in both places -- one with the raw viewer zone, one
     * with the sanitised bucket zone -- so `sharedRangeCacheKey()` never matched for any
     * viewer whose zone `safeTimezone()` does not recognise).
     *
     * @return array{start: string, end: string}
     */
    public static function currentMonthWindow(string $bucketZone): array
    {
        return [
            'start' => now(tz: $bucketZone)->startOfMonth()->startOfDay()->toDateTimeString(),
            'end' => now(tz: $bucketZone)->endOfDay()->toDateTimeString(),
        ];
    }

    /**
     * The previous WHOLE calendar month, cut in the BUCKET zone -- the sibling of
     * {@see currentMonthWindow()}, and the same formula both cards must use for the
     * `last_month` filter.
     *
     * Both month-granularity arms belong on the bucket zone, not just `this_month`.
     * `DashboardWidget::getCardTotal()` routes BOTH `this_month` and `last_month` to
     * `getMonthlyData()` -- the bucket-zone 13-month series -- and only `today`/`last_7d`
     * to the raw-zone `getRangeTotal()`. So the number UniqueScanCounts DISPLAYS for Last
     * Month is a bucket-zone month. An earlier revision of DEV-2468 fixed `this_month`
     * alone and left `last_month` on the raw zone, which for an unlisted viewer zone (the
     * `safeTimezone()` fallback cases: `CST`, `PST`, `+05:00`) made the two cards report
     * DIFFERENT MONTHS side by side on the same dashboard row.
     *
     * `startOfMonth()` precedes `subMonth()` so resolving on the 31st cannot overflow.
     *
     * @return array{start: string, end: string}
     */
    public static function lastMonthWindow(string $bucketZone): array
    {
        $lastMonth = now(tz: $bucketZone)->startOfMonth()->subMonth();

        return [
            'start' => $lastMonth->copy()->startOfDay()->toDateTimeString(),
            'end' => $lastMonth->copy()->endOfMonth()->endOfDay()->toDateTimeString(),
        ];
    }

    /**
     * The DEV-2468 sharing seam: one spelling of the window-rollup cache key, so this
     * card's currently-selected-window read and DEV-2468's leaderboard read the SAME
     * cached `handleRange()` result rather than each issuing their own scan.
     *
     * `$timezone` is part of the key -- the window bounds a caller passes here are
     * resolved from viewer-local wall-clock time (e.g. "today" starts at local midnight),
     * so a Chicago and a London viewer on the same org resolve DIFFERENT UTC windows for
     * the "same" `$start`/`$end` local strings. Without `$timezone` in the key they would
     * collide and one viewer would read the other's cached rollup. Mirrors
     * `StagingLiveHitLeader::leaderCacheKey()`'s `:tz=` segment.
     *
     * Deliberately NOT `DashboardWidget::buildWidgetCacheKey()` -- that prefixes the
     * calling WIDGET's class basename, which would make this key unreadable from a
     * sibling card's class.
     *
     * CONTRACT, and it is the whole of it: pass the IDENTICAL `$start`/`$end`/`$timezone`
     * you pass to handleRange(). This key describes that call's result, and handleRange()
     * uses the zone verbatim, so normalising here would make the key describe a window the
     * query never ran. Two callers share a scan when and only when all three arguments
     * match -- which is exactly when the underlying query is the same.
     *
     * Over-keying is safe and under-keying is not: two zones that happen to resolve alike
     * merely miss a cache hit, whereas one key covering two different windows serves a
     * viewer someone else's numbers.
     *
     * VERSIONED (`-v2`): DEV-2468 changed `handleRange()`'s return shape from
     * `array<int, int>` to `{totals: array<int, int>, names: array<int, string>}`. Without
     * the bump, an in-flight entry written in the old shape could be deserialised by the
     * new code and read as a confidently wrong result -- a live production break, not a
     * test failure. Bump this suffix again the next time the payload shape changes.
     */
    public static function sharedRangeCacheKey(int $orgId, string $start, string $end, string $timezone): string
    {
        self::assertDatetime($start, 'start');
        self::assertDatetime($end, 'end');

        $key = "scan-plate-stats:unique-range-v2:org={$orgId}:{$start}:{$end}:tz={$timezone}";

        return str_replace(' ', '', $key);
    }

    /**
     * DEV-2468's Q1 Option A sharing seam: the ORG-TOTAL monthly series, so both
     * UniqueScanCounts and the leaderboard's sparkline read the SAME cached `handle()`
     * bucket for a given org+month+timezone rather than each maintaining its own
     * class-prefixed copy.
     *
     * Mirrors sharedRangeCacheKey()'s contract: pass the identical `$monthKey`/`$timezone`
     * both callers resolve. `$monthKey` is the `Y-m` the caller buckets by, including
     * the open month. The dashboard date-filter value `this_month` is a different
     * string and belongs on {@see sharedRangeCacheKey()}, not here.
     *
     * VERSIONED (`-v1`) from the start, unlike the pre-DEV-2468 history of
     * sharedRangeCacheKey() -- see that key's `-v2` bump. Bump this suffix the day this
     * key's payload shape (currently a bare int) ever changes, for the same reason: an
     * in-flight entry in the old shape read by new code is a live production break.
     */
    /**
     * How long a recorded ClickHouse failure suppresses re-attempts on this namespace.
     */
    public const FETCH_FAILURE_TTL_SECONDS = 60;

    /**
     * The shared "this org's scans_cold aggregate just failed" marker.
     *
     * Lives HERE, on the class that owns the `scan-plate-stats:` namespace, rather than on any
     * one card -- there is now more than one writer of these keys, and a backoff only works if
     * every writer observes it. UniqueScanCounts (the original author) delegates to this.
     *
     * Deliberately org-scoped and NOT timezone- or class-scoped. Adding either segment would
     * give each zone (or each card) its own backoff and let N of them hammer a hard-down
     * ClickHouse N times inside the same window -- the opposite of what the marker is for.
     */
    public static function sharedFetchFailureMarkerKey(int $orgId): string
    {
        return 'scan-plate-stats:unique-fetch-failed:past-months:org='.$orgId;
    }

    public static function sharedMonthCacheKey(int $orgId, string $monthKey, string $timezone): string
    {
        $key = "scan-plate-stats:unique-month-v1:org={$orgId}:{$monthKey}:tz={$timezone}";

        return str_replace(' ', '', $key);
    }

    /**
     * The shared range rollup. A hit returns the cached handleRange() payload.
     * A miss runs handleRange() and stores it under {@see sharedRangeCacheKey()}.
     * The TTL is the ladder's open arm (10 minutes, 10 seconds while impersonating).
     *
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    public static function rememberedRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return cache()->remember(
            self::sharedRangeCacheKey($orgId, $start, $end, $timezone),
            self::openSharedTtl(),
            fn (): array => self::handleRange([
                'org_id' => $orgId,
                'start' => $start,
                'end' => $end,
                'timezone' => $timezone,
            ]),
        );
    }

    public static function cachedMonthInt(int $orgId, string $monthKey, string $timezone): ?int
    {
        $value = cache()->get(self::sharedMonthCacheKey($orgId, $monthKey, $timezone));

        return $value === null ? null : (int) $value;
    }

    /**
     * @param  list<string>  $monthKeys
     * @return array<string, int>
     */
    public static function cachedMonthInts(int $orgId, string $timezone, array $monthKeys): array
    {
        $result = [];

        foreach ($monthKeys as $monthKey) {
            $value = self::cachedMonthInt($orgId, $monthKey, $timezone);

            if ($value !== null) {
                $result[$monthKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Shared month ints. Reads each Y-m key, runs $fetch once for the cold span,
     * and stores each missing month under {@see sharedMonthCacheKey()}.
     *
     * $openMonthKey uses the ladder's open arm. Every other month uses
     * {@see sharedClosedMonthTtl()}. $spanEndCap clamps the batch end the way a
     * caller that includes the open month must (the end is `through`, not a
     * future end-of-month). $fetch throws on failure; this method writes nothing
     * in that case.
     *
     * @param  list<string>  $monthKeys
     * @param  callable(string, string): array<string, int>  $fetch
     * @return array<string, int>
     */
    public static function rememberMonthSeries(
        int $orgId,
        string $timezone,
        array $monthKeys,
        ?string $openMonthKey,
        ?\DateTimeInterface $spanEndCap,
        callable $fetch,
    ): array {
        $result = self::cachedMonthInts($orgId, $timezone, $monthKeys);
        $uncached = array_values(array_filter(
            $monthKeys,
            fn (string $monthKey): bool => ! array_key_exists($monthKey, $result),
        ));

        if ($uncached === []) {
            return $result;
        }

        $sorted = $uncached;
        sort($sorted);
        $spanStart = self::monthStart($sorted[0], $timezone)->startOfDay();
        $spanEnd = self::monthStart($sorted[array_key_last($sorted)], $timezone)->endOfMonth()->endOfDay();

        if ($spanEndCap !== null) {
            $cap = Carbon::parse($spanEndCap);

            if ($spanEnd->greaterThan($cap)) {
                $spanEnd = $cap;
            }
        }

        $batch = $fetch($spanStart->toDateTimeString(), $spanEnd->toDateTimeString());

        foreach ($uncached as $monthKey) {
            $value = (int) ($batch[$monthKey] ?? 0);
            $result[$monthKey] = $value;
            $ttl = ($openMonthKey !== null && $monthKey === $openMonthKey)
                ? self::openSharedTtl()
                : self::sharedClosedMonthTtl(self::monthStart($monthKey, $timezone)->endOfMonth());

            cache()->put(self::sharedMonthCacheKey($orgId, $monthKey, $timezone), $value, $ttl);
        }

        return $result;
    }

    /**
     * The open-month org total, stored as the Y-m int the sparkline reads.
     * $fetchRange is the shared range rollup for that window; this method does
     * not call it when the int is already warm.
     *
     * @param  callable(): array{totals: array<int, int>, names: array<int, string>}  $fetchRange
     */
    public static function rememberOpenMonthTotal(int $orgId, string $monthKey, string $timezone, callable $fetchRange): int
    {
        $cached = self::cachedMonthInt($orgId, $monthKey, $timezone);

        if ($cached !== null) {
            return $cached;
        }

        $rollup = $fetchRange();
        $value = (int) ($rollup['totals'][0] ?? 0);

        cache()->put(
            self::sharedMonthCacheKey($orgId, $monthKey, $timezone),
            $value,
            self::openSharedTtl(),
        );

        return $value;
    }

    public static function sharedClosedMonthTtl(?\DateTimeInterface $monthEndedAt = null): \DateTimeInterface
    {
        $ttl = (new static)->baseDashboardCacheTTL(1, $monthEndedAt);
        $floor = now()->addMinutes(self::IMPERSONATED_PAST_MONTH_TTL_MINUTES);

        return $ttl->getTimestamp() < $floor->getTimestamp() ? $floor : $ttl;
    }

    private static function openSharedTtl(): \DateTimeInterface
    {
        return (new static)->baseDashboardCacheTTL(0);
    }

    private static function monthStart(string $monthKey, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $monthKey.'-01', $timezone);
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

    /**
     * DEV-2651 review-2. Public face of {@see toUtcDatetime()} for
     * {@see ScanStatsBatchQuery}, whose series is co-plotted with this one and so must cut
     * its bounds and its grouping on the identical zone. Exposed rather than duplicated: the
     * validation below (convert first, then match DATETIME_PATTERN) is the part that must not
     * drift between the two.
     */
    public static function toUtcDatetimeFor(string $value, string $timezone, string $field): string
    {
        return self::toUtcDatetime($value, $timezone, $field);
    }
}
