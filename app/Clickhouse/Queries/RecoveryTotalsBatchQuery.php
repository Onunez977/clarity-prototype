<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\RecoveryCounts;
use Illuminate\Support\Facades\DB;

/**
 * DEV-2470. Total repossessions from `clarity.recoveries` -- the same quantity
 * {@see RecoveryCounts} renders as "Total Repossessions".
 *
 * RecoveryCounts already calls {@see handle()} for the 13-month series and
 * {@see handleRange()} for Today / Last 7 Days. Both cache writes are bare ints
 * under the class-neutral keys ({@see sharedMonthCacheKey()}, {@see sharedWindowCacheKey()}).
 * {@see RecoveryCounts::getModelQueryForDates()} returns 0 and is not the live path.
 * Eloquent `Recovery::count()` parity lives in RecoveryTotalsBatchQueryTest, not in the card.
 *
 * UniqueScansToRepo was deleted in the prior Total Repossessions batch landing. The scans-per-repo denominator is
 * {@see RecoveryCounts::getMonthlyData()} on RecoveryCounts, which reads the series
 * this query already wrote. There is no second repo query.
 *
 * `FINAL` IS REQUIRED HERE, and this is the opposite of the sibling scan query. Measured on
 * org 1271 over a 13-month window: 39,358 rows with `FINAL` against 46,313 without -- omitting
 * it over-counts repossessions by 17.7%, because recovery rows genuinely mutate. Do NOT
 * generalise DEV-2466's `uniqExact(ocr)`-on-`scans_cold` result (where `FINAL` was pure
 * overhead for an identical answer) to this table.
 *
 * `count()`, not `uniqExact(id)`. The two still drift by 2-7 rows per org over 13 months even
 * WITH `FINAL` -- the DEV-2406 `hits_cold` defect class, where a mutable sort-key column
 * (`repo_date`, second in `ORDER BY (org_id, repo_date, id)`) stops `FINAL` collapsing every
 * duplicate. That is ~0.02% and invisible at the card's one-decimal precision, so the tie is
 * broken toward `count()`: it is the quantity `Recovery::count()` still pins in
 * RecoveryTotalsBatchQueryTest. The card does not reach that count through
 * {@see RecoveryCounts::getModelQueryForDates()}.
 *
 * Envelope, measured read-only against production ClickHouse Cloud 2026-09-14: the 13-month
 * single-org series reads 281,882 rows / 5.91 MB / 52.3 MB peak in ~15 ms against a 17,059,091-row
 * / 776.25 MiB table; the largest single org in the same window is 240,140 rows. `org_id` LEADS
 * the sort key, so an org-scoped read prunes properly -- this is not the `scans_cold`/`hits_cold`
 * `kit_id` trap. That is ~2,000x lighter than the funnel leg DEV-2469 wrapped in cold-mutex
 * machinery, which is why no such machinery is warranted here.
 *
 * Extends ClickhouseQuery only for {@see ClickhouseQuery::clarityDb()}. Callers MUST use
 * handle()/handleRange() directly and NEVER query(): that base swallows any Throwable to `[]`
 * in production and testing, and a zero denominator is a NORMAL reading on this card, so a
 * swallowed failure would render as 0.0 indistinguishably from real data.
 */
class RecoveryTotalsBatchQuery extends ClickhouseQuery
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * The 13-month series, keyed `Y-m`.
     *
     * Bucketed by `toStartOfMonth(repo_date)` in SQL rather than by PHP-computed month
     * boundaries. `repo_date` is a ClickHouse `Date` with no time-of-day, so this expression
     * carries NO timezone axis at all -- which is what lets the series divide safely into a
     * numerator pinned to UTC. Reusing `RecoveryCounts`' viewer-timezone month boundaries would
     * reintroduce a day-level version of the mixed-clock defect DEV-2469 shipped and had to fix.
     *
     * Keyed `Y-m`, deliberately NOT the `Y-m-01` that `toStartOfMonth` returns: the numerator
     * leg keys `Y-m`, and the sibling card's most fragile line is the reconciliation between the
     * two shapes. Normalising here removes that seam rather than guarding it.
     */
    public static function handleSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        $start = self::assertDate((string) $arguments['start'], 'start');
        $end = self::assertDate((string) $arguments['end'], 'end');

        $fromAndWhere = self::fromAndWhere(static::clarityDb(), $orgId, $start, $end);

        return <<<SQL
            SELECT
                toStartOfMonth(repo_date) AS month,
                count() AS total_repos
            {$fromAndWhere}
            GROUP BY month
            ORDER BY month
            SQL;
    }

    /**
     * The 13-month series, keyed `Y-m`.
     *
     * Bucketed by `toStartOfMonth(repo_date)` in SQL rather than by PHP-computed month
     * boundaries. `repo_date` is a ClickHouse `Date` with no time-of-day, so this expression
     * carries NO timezone axis at all -- which is what lets the series divide safely into a
     * numerator pinned to UTC. Reusing `RecoveryCounts`' viewer-timezone month boundaries would
     * reintroduce a day-level version of the mixed-clock defect DEV-2469 shipped and had to fix.
     *
     * Keyed `Y-m`, deliberately NOT the `Y-m-01` that `toStartOfMonth` returns: the numerator
     * leg keys `Y-m`, and the sibling card's most fragile line is the reconciliation between the
     * two shapes. Normalising here removes that seam rather than guarding it.
     *
     * @return array<string, int>
     */
    public static function handle(array $arguments = []): array
    {
        $result = [];

        foreach (DB::connection('clarity_clickhouse')->select(self::handleSql($arguments)) as $row) {
            $result[date('Y-m', strtotime((string) $row['month']))] = (int) $row['total_repos'];
        }

        return $result;
    }

    /**
     * One bounded window's scalar total, for the Today / Last 7 Days arms.
     *
     * Both bounds are INCLUSIVE dates, matching `Recovery::scopeBetween()` exactly so this and
     * `RecoveryCounts` cannot disagree about what a window contains.
     */
    public static function handleRangeSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        $start = self::assertDate((string) $arguments['start'], 'start');
        $end = self::assertDate((string) $arguments['end'], 'end');

        $fromAndWhere = self::fromAndWhere(static::clarityDb(), $orgId, $start, $end);

        return <<<SQL
            SELECT count() AS total_repos
            {$fromAndWhere}
            SQL;
    }

    public static function handleRange(array $arguments = []): int
    {
        $rows = DB::connection('clarity_clickhouse')->select(self::handleRangeSql($arguments));

        return (int) ($rows[0]['total_repos'] ?? 0);
    }

    /**
     * The one FROM + WHERE both entry points share, so the org, date and tombstone filters can
     * never drift apart between the series and the window.
     *
     * `FINAL` and `_peerdb_is_deleted = 0` together replicate what `Recovery`'s `ApplyFinal` and
     * `FiltersCdcDeletes` global scopes apply for `RecoveryCounts`. Raw SQL bypasses both, so
     * they are restated here rather than inherited.
     *
     * The tombstone predicate is REDUNDANT as long as `FINAL` is present, and the docblock says
     * so rather than implying otherwise: the engine is `ReplacingMergeTree(_version,
     * _peerdb_is_deleted)`, the two-argument form, so `FINAL` already drops deleted rows -- the
     * query test still passes with this predicate removed. It stays because it is the house
     * convention, because it keeps this read identical to the Eloquent path it must agree with,
     * and because it is the guarantee that survives if `FINAL` is ever dropped for cost. It is
     * a UInt8 comparison, not a sort-key prune, so it is very nearly free.
     */
    private static function fromAndWhere(string $clarityDb, int $orgId, string $start, string $end): string
    {
        return <<<SQL
            FROM {$clarityDb}.recoveries FINAL
            WHERE org_id = {$orgId}
              AND repo_date >= toDate('{$start}')
              AND repo_date <= toDate('{$end}')
              AND _peerdb_is_deleted = 0
            SQL;
    }

    /**
     * Class-neutral cache key for ONE month of the series.
     *
     * Deliberately not {@see DashboardWidget::buildWidgetCacheKey()},
     * which prefixes the calling widget's `class_basename` and would make the entry unreadable
     * from any sibling card -- the trap `okf/concepts/company-metrics-dashboard.md` records.
     * RecoveryCounts already probes and writes this key around {@see handle()}.
     *
     * Carries NO `:tz=` segment, and must not grow one: `toStartOfMonth(repo_date)` is timezone-free,
     * so the value is identical for every viewer of an org. Forking it per zone would multiply the
     * read for nothing. Do not change the `-v1` suffix while the payload stays a bare int.
     *
     * PAYLOAD CONTRACT: this key carries an `int` -- one month's total. Nothing else. A writer
     * storing another shape corrupts every reader SILENTLY (`$int['x'] ?? 0` is 0 and `(int) $array`
     * is 1; neither warns), which is exactly how DEV-2469 RN3 broke two shipped cards at once. If
     * the shape must change, bump the `-v1` suffix and migrate every writer together.
     */
    public static function sharedMonthCacheKey(int $orgId, string $monthKey): string
    {
        return "recovery-totals:month-v1:org={$orgId}:{$monthKey}";
    }

    /**
     * Class-neutral cache key for ONE resolved window. It stays shared.
     *
     * PAYLOAD CONTRACT: an `int` -- {@see handleRange()}'s total. See {@see sharedMonthCacheKey()}.
     *
     * Unlike the month key this DOES carry the viewer's raw timezone: Today and Last 7 Days
     * are a viewer-local window (they end at the viewer's local end-of-day), so two zones are
     * two genuinely different windows. The earlier window-key correction re-shared this key as an int after
     * the withdrawn un-share; the timezone segment is why the share is correct.
     *
     * {@see DashboardWidget::currentPeriodTotal()} also `cache()->remember()`s the headline under
     * {@see DashboardWidget::buildWidgetCacheKey()}, a class-prefixed outer layer. That outer
     * remember is not a reason to un-share this key.
     */
    public static function sharedWindowCacheKey(int $orgId, string $dateType, string $day, string $timezone): string
    {
        return str_replace(' ', '', "recovery-totals:window-v1:org={$orgId}:{$dateType}:{$day}:tz={$timezone}");
    }

    /**
     * Strict allowlist for every interpolated date. `addslashes()` is not a SQL escape, and these
     * bounds are derived from `users.dti`-influenced viewer timezones upstream.
     */
    private static function assertDate(string $value, string $field): string
    {
        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field} date: {$value}");
        }

        return $value;
    }
}
