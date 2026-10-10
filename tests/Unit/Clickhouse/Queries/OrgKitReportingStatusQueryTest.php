<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\OrgKitReportingStatusQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class OrgKitReportingStatusQueryTest extends TestCase
{
    use RequiresClickhouse;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('clickhouse')]
    public function test_empty_mappings_returns_an_empty_array_without_querying(): void
    {
        $this->assertSame([], OrgKitReportingStatusQuery::handle(['mappings' => []]));
    }

    #[Group('clickhouse')]
    public function test_missing_mappings_returns_an_empty_array(): void
    {
        $this->assertSame([], OrgKitReportingStatusQuery::handle([]));
    }

    #[Group('clickhouse')]
    public function test_a_fix_thirteen_days_old_is_within_the_default_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

        $this->insertScPosition('reporting-kit-01', now()->subDays(13));

        $result = OrgKitReportingStatusQuery::handle(['mappings' => ['reporting-kit-01']]);

        $this->assertArrayHasKey('reporting-kit-01', $result);
    }

    #[Group('clickhouse')]
    public function test_a_fix_fifteen_days_old_is_outside_the_default_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

        $this->insertScPosition('stale-kit-01', now()->subDays(15));

        $result = OrgKitReportingStatusQuery::handle(['mappings' => ['stale-kit-01']]);

        $this->assertArrayNotHasKey('stale-kit-01', $result);
    }

    /**
     * Platform lowercases kit_mapping; the scanner feed does not. A row stored
     * uppercase must still match a lowercase caller mapping, and vice versa —
     * the #1 regression guard for this query.
     */
    #[Group('clickhouse')]
    public function test_matching_is_case_insensitive_and_the_returned_key_is_lowercased(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

        $this->insertScPosition('CASE-KIT-02', now()->subDays(1));

        $result = OrgKitReportingStatusQuery::handle(['mappings' => ['case-kit-02']]);

        $this->assertArrayHasKey('case-kit-02', $result);
        $this->assertArrayNotHasKey('CASE-KIT-02', $result);
    }

    #[Group('clickhouse')]
    public function test_a_lowercase_caller_mapping_matches_an_uppercase_stored_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

        $this->insertScPosition('UPPER-KIT-03', now()->subDays(1));

        $result = OrgKitReportingStatusQuery::handle(['mappings' => ['UPPER-KIT-03']]);

        $this->assertArrayHasKey('upper-kit-03', $result);
    }

    #[Group('clickhouse')]
    public function test_several_fixes_in_window_return_the_newest_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

        $oldest = now()->subDays(5)->startOfSecond();
        $middle = now()->subDays(3)->startOfSecond();
        $newest = now()->subDay()->startOfSecond();

        $this->insertScPosition('multi-fix-kit-04', $oldest, -97.5000);
        $this->insertScPosition('multi-fix-kit-04', $newest, -90.0000);
        $this->insertScPosition('multi-fix-kit-04', $middle, -95.0000);

        $result = OrgKitReportingStatusQuery::handle(['mappings' => ['multi-fix-kit-04']]);

        // Both sides pinned to UTC — see the insert helper; relying on setTestNow's
        // timezone to propagate through now() is luck, not a contract.
        $this->assertSame(
            $newest->copy()->utc()->format('Y-m-d H:i:s'),
            Carbon::parse($result['multi-fix-kit-04'], 'UTC')->format('Y-m-d H:i:s')
        );
    }

    /**
     * A kit_mapping of "0" is falsy in PHP, so a bare array_filter() would drop it
     * before the query and the kit would read "not reporting" forever. Production
     * has single-character mappings, so this is reachable, and the failure would be
     * silent.
     */
    #[Group('clickhouse')]
    public function test_a_falsy_but_valid_mapping_is_not_dropped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

        $this->insertScPosition('0', now()->subDay());

        $result = OrgKitReportingStatusQuery::handle(['mappings' => ['0']]);

        $this->assertArrayHasKey('0', $result);
    }

    #[Group('clickhouse')]
    public function test_a_mapping_with_no_rows_at_all_is_absent_from_the_result(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

        $this->insertScPosition('has-rows-kit-05', now()->subDay());

        $result = OrgKitReportingStatusQuery::handle([
            'mappings' => ['has-rows-kit-05', 'no-rows-kit-06'],
        ]);

        $this->assertArrayHasKey('has-rows-kit-05', $result);
        $this->assertArrayNotHasKey('no-rows-kit-06', $result);
    }

    /**
     * `timestamp_utc` is a UTC column, so the instant is converted to UTC here rather
     * than at each call site — a naive `now()`-derived string would be expressed in
     * APP_TIMEZONE and silently shift the 14-day boundary.
     */
    private function insertScPosition(string $kitMapping, Carbon $timestampUtc, float $lng = -97.7400): void
    {
        DB::connection('clarity_clickhouse')->table('sc_positions')->insert([
            'kit_mapping' => $kitMapping,
            'geo' => "({$lng}, 30.3000)",
            'timestamp_utc' => $timestampUtc->copy()->utc()->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ]);
    }
}
