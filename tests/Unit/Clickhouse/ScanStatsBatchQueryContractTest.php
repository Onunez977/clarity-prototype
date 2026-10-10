<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Clickhouse\Queries\ScanStatsBatchQuery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * DEV-2651 review-4 R4-D. `ScanStatsBatchQuery::handleSql()` is the ONLY executable change this
 * ticket made to a query class, and it required an explicit operator override of the ticket's own
 * "do not touch app/Clickhouse" rule. It shipped with no test.
 *
 * `CardQuerySqlFidelityTest` was cited as the safety net and is not one: it asserts only that
 * `handle()` delegates to `handleSql()` and builds no inline SQL. Reverting the grouping
 * expression to the pre-diff `toStartOfMonth(scan_hour)` leaves that test — and the whole suite —
 * green while restoring the mixed-calendar defect the change exists to fix.
 *
 * `handleSql()` is a pure string function, so none of this needs a ClickHouse connection. This
 * lives in Tests\Unit\Clickhouse rather than Tests\Feature\Clickhouse deliberately: the Feature
 * classes `use RequiresClickhouse` at class level, which skips every method when
 * CLICKHOUSE_DRIVER != clickhouse — these guards would then be invisible on a normal local run,
 * which is the same trap ScanPlateStatsBatchQueryContractTest was split out to escape.
 */
class ScanStatsBatchQueryContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function args(string $timezone): array
    {
        return [
            'org_id' => 7,
            'start' => '2026-08-01 00:00:00',
            'end' => '2026-08-31 23:59:59',
            'timezone' => $timezone,
        ];
    }

    /**
     * The defect: this series is co-plotted with ScanPlateStatsBatchQuery's distinct-plate
     * series, which groups in the viewer's zone. Grouping on raw UTC `scan_hour` put the last
     * hours of every local month in the next bar, so a non-UTC viewer could see a raw total
     * BELOW the distinct-plate count on the same bar.
     */
    #[Group('clickhouse')]
    #[Group('dashboard')]
    public function test_the_month_grouping_is_timezone_aware_not_raw_scan_hour(): void
    {
        $sql = ScanStatsBatchQuery::handleSql($this->args('America/Chicago'));

        $this->assertStringContainsString(
            "toStartOfMonth(toTimeZone(scan_hour, 'America/Chicago'))",
            $sql,
            'the grouping expression must be cut in the viewer zone, or this series desynchronises from the distinct-plate series beside it'
        );
        $this->assertStringNotContainsString(
            'toStartOfMonth(scan_hour)',
            $sql,
            'raw scan_hour grouping is the pre-diff defect'
        );
    }

    /**
     * ONE zone for the bounds AND the grouping. Splitting them lets the grouping emit a month
     * key outside the caller's write set, silently discarding the rows in the gap — the
     * invariant ScanPlateStatsBatchQuery's docblock calls load-bearing.
     */
    #[Group('clickhouse')]
    #[Group('dashboard')]
    public function test_the_bounds_are_utc_converted_with_the_same_zone_that_feeds_the_grouping(): void
    {
        $sql = ScanStatsBatchQuery::handleSql($this->args('America/Chicago'));

        // Chicago is UTC-5 in August, so the local-midnight bound converts forward 5 hours.
        $this->assertStringContainsString("scan_hour >= '2026-08-01 05:00:00'", $sql);
        $this->assertStringContainsString("scan_hour <= '2026-09-01 04:59:59'", $sql);

        // The WHERE must still filter the RAW column so org_id/scan_hour pruning is unaffected.
        $this->assertStringNotContainsString('toTimeZone(scan_hour', explode('WHERE', $sql)[1]);
    }

    /**
     * `users.dti` is written straight from the request with no validation, so `+05:00` and
     * `CST` both construct a valid DateTimeZone while being absent from the IANA list.
     * safeTimezone() maps those to UTC rather than throwing — a throw here would take down
     * every card, since CompanyMetricsOverview::getStats() has no per-card guard.
     */
    #[Group('clickhouse')]
    #[Group('dashboard')]
    public function test_an_unlisted_timezone_falls_back_to_utc_rather_than_interpolating_it(): void
    {
        foreach (['+05:00', 'CST', 'Not/AZone'] as $unlisted) {
            $sql = ScanStatsBatchQuery::handleSql($this->args($unlisted));

            $this->assertStringContainsString("toStartOfMonth(toTimeZone(scan_hour, 'UTC'))", $sql, "{$unlisted} must fall back to UTC");
            $this->assertStringNotContainsString($unlisted, $sql, "{$unlisted} must never reach the SQL");
        }
    }

    /**
     * DEV-2616. The database prefix must come from clarityDb(), not be hardcoded, or this query
     * can only ever address the production database name — it was the one query on the card that
     * could not run against a per-worktree local QA database.
     */
    #[Group('clickhouse')]
    #[Group('dashboard')]
    public function test_the_database_prefix_is_resolved_not_hardcoded(): void
    {
        config(['database.connections.clarity_clickhouse.driver' => 'clickhouse']);
        config(['database.connections.clarity_clickhouse.database' => 'clarity_some_worktree']);

        $sql = ScanStatsBatchQuery::handleSql($this->args('UTC'));

        $this->assertStringContainsString('FROM clarity_some_worktree.scan_report_hourly', $sql);
        $this->assertStringNotContainsString('FROM clarity.scan_report_hourly', $sql);
    }

    /** A malformed bound must be rejected, not interpolated — behaviour addslashes() never had. */
    #[Group('clickhouse')]
    #[Group('dashboard')]
    public function test_a_malformed_bound_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScanStatsBatchQuery::handleSql([
            'org_id' => 7,
            'start' => "2026-08-01'; DROP TABLE x --",
            'end' => '2026-08-31 23:59:59',
            'timezone' => 'UTC',
        ]);
    }
}
