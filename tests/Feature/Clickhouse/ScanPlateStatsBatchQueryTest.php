<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

// clarity.scans_cold carries no TTL, so these rows would survive unanchored -- pinned to
// a genuinely frozen now() (Carbon::setTestNow() in setUp()) for deterministic month-key
// assertions, since several tests below call now() more than once while building fixture
// timestamps and query windows.
#[Group('clickhouse')]
class ScanPlateStatsBatchQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const ORG_ID = 742;

    private const OTHER_ORG_ID = 743;

    private int $scanSeq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();

        Carbon::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_handle_dedupes_repeated_ocr_within_the_same_month(): void
    {
        // A bare count() would return 3; uniqExact(ocr) must return 2.
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);
        $this->seedScan(['ocr' => 'AAA1111', 'scanned_at' => $month->toDateTimeString()]);
        $this->seedScan(['ocr' => 'AAA1111', 'scanned_at' => $month->copy()->addHours(1)->toDateTimeString()]);
        $this->seedScan(['ocr' => 'BBB2222', 'scanned_at' => $month->copy()->addHours(2)->toDateTimeString()]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame(2, $result[$monthKey]);
    }

    public function test_handle_does_not_collapse_the_same_ocr_across_different_months(): void
    {
        $thisMonth = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);
        $lastMonth = now()->startOfMonth()->subMonths(1)->addDays(5)->setTime(10, 0, 0);

        $this->seedScan(['ocr' => 'CCC3333', 'scanned_at' => $thisMonth->toDateTimeString()]);
        $this->seedScan(['ocr' => 'CCC3333', 'scanned_at' => $lastMonth->toDateTimeString()]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(2)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $thisMonthKey = $thisMonth->format('Y-m');
        $lastMonthKey = $lastMonth->format('Y-m');

        $this->assertArrayHasKey($thisMonthKey, $result);
        $this->assertArrayHasKey($lastMonthKey, $result);
        $this->assertSame(1, $result[$thisMonthKey]);
        $this->assertSame(1, $result[$lastMonthKey]);
    }

    public function test_handle_scopes_by_org_id_and_excludes_a_second_org(): void
    {
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedScan(['ocr' => 'DDD4444', 'scanned_at' => $month->toDateTimeString(), 'org_id' => self::ORG_ID]);
        $this->seedScan(['ocr' => 'EEE5555', 'scanned_at' => $month->toDateTimeString(), 'org_id' => self::OTHER_ORG_ID]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame(1, $result[$monthKey]);
    }

    public function test_handle_dup_group_with_an_unchanged_ocr_does_not_inflate_the_count_without_final(): void
    {
        // DEV-2466 sec 5's regression check: on production, of 1,635 duplicate-id CDC
        // groups, ZERO carried more than one distinct ocr -- ocr is immutable across a
        // scan's CDC versions (unlike hits_cold's mutable hit_created_at). Two raw,
        // unmerged rows sharing an id/org_id/scanned_at (the sort key) with the SAME ocr
        // must not double-count it even without FINAL.
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);
        $id = $this->scanSeq++;

        $this->seedScan([
            'id' => $id,
            'ocr' => 'SAME0001',
            'scanned_at' => $month->toDateTimeString(),
            '_version' => 1,
        ]);
        $this->seedScan([
            'id' => $id,
            'ocr' => 'SAME0001',
            'scanned_at' => $month->toDateTimeString(),
            '_version' => 2,
        ]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame(1, $result[$monthKey]);
    }

    public function test_handle_excludes_soft_deleted_rows(): void
    {
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedScan(['ocr' => 'KEEP0001', 'scanned_at' => $month->toDateTimeString()]);
        $this->seedScan([
            'ocr' => 'GONE0001',
            'scanned_at' => $month->toDateTimeString(),
            '_peerdb_is_deleted' => 1,
        ]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame(1, $result[$monthKey]);
    }

    public function test_handle_groups_months_by_the_callers_timezone_not_utc(): void
    {
        // S2(b): Chicago is UTC-5 in August (CDT). A scan at 23:30 Chicago on the last
        // day of the month is 04:30 UTC the FOLLOWING day -- grouping by raw (UTC)
        // scanned_at would land it in September; toStartOfMonth(toTimeZone(...)) must
        // keep it in August, agreeing with DashboardWidget::getGraphLabels()'s
        // viewer-timezone bar labels.
        $chicagoLastNightOfAugust = Carbon::parse('2026-08-31 23:30:00', 'America/Chicago');

        $this->seedScan([
            'ocr' => 'TZB0001',
            'scanned_at' => $chicagoLastNightOfAugust->clone()->utc()->toDateTimeString(),
        ]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => '2026-08-01 00:00:00',
            'end' => '2026-09-30 23:59:59',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertSame(1, $result['2026-08'] ?? 0, 'a scan at 23:30 Chicago on Aug 31 must bucket into August, not September');
        $this->assertArrayNotHasKey('2026-09', $result);
    }

    public function test_handle_range_converts_naive_local_window_bounds_to_utc(): void
    {
        // S2(a): "today" in Chicago (UTC-5 in August) begins at 05:00 UTC. A scan 30
        // minutes before that boundary is still YESTERDAY in Chicago and must be
        // excluded from a Chicago "today" window even though ScanPlateStatsBatchQuery
        // receives the same naive '2026-08-15 00:00:00' string App\Clickhouse\Models\
        // Scan::scopeBetween() would receive for the same local day.
        $chicagoTodayStartUtc = Carbon::parse('2026-08-15 00:00:00', 'America/Chicago')->utc();

        $this->seedScan([
            'ocr' => 'TZR0001',
            'scanned_at' => $chicagoTodayStartUtc->clone()->subMinutes(30)->toDateTimeString(),
        ]);
        $this->seedScan([
            'ocr' => 'TZR0002',
            'scanned_at' => $chicagoTodayStartUtc->clone()->addMinutes(30)->toDateTimeString(),
        ]);

        $result = ScanPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => '2026-08-15 00:00:00',
            'end' => '2026-08-15 23:59:59',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertSame(1, $result['totals'][0], 'only the scan inside the Chicago-local window must count');
    }

    public function test_handle_range_rollup_kit_zero_equals_the_standalone_org_total(): void
    {
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedScan(['ocr' => 'RNG0001', 'scanned_at' => $month->toDateTimeString(), 'kit_id' => 11]);
        $this->seedScan(['ocr' => 'RNG0001', 'scanned_at' => $month->copy()->addHours(1)->toDateTimeString(), 'kit_id' => 12]);
        $this->seedScan(['ocr' => 'RNG0002', 'scanned_at' => $month->toDateTimeString(), 'kit_id' => 12]);
        $this->seedScan([
            'ocr' => 'RNG0003',
            'scanned_at' => $month->toDateTimeString(),
            'kit_id' => 99,
            'org_id' => self::OTHER_ORG_ID,
        ]);

        $result = ScanPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => $month->copy()->startOfMonth()->toDateTimeString(),
            'end' => $month->copy()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        // RNG0001 is scanned by two kits within the same org, so per-kit counts sum to 3
        // (1 + 2) while the org-wide distinct-plate total is 2 -- proving WITH ROLLUP's
        // kit_id=0 row is a genuine re-dedup, not a sum of the per-kit rows.
        $this->assertSame(2, $result['totals'][0]);
        $this->assertSame(1, $result['totals'][11]);
        $this->assertSame(2, $result['totals'][12]);
        $this->assertArrayNotHasKey(99, $result['totals']);

        // anyLast(kit_name) carries the label DEV-2468's leaderboard needs, keyed by kit
        // id -- never by name, and never for the rollup row.
        $this->assertArrayNotHasKey(0, $result['names']);
        $this->assertArrayHasKey(11, $result['names']);
        $this->assertArrayHasKey(12, $result['names']);
    }

    public function test_handle_range_key_zero_is_the_org_total_even_with_a_real_kit_zero(): void
    {
        // Characterisation of the documented limitation: a real kit 0 and the rollup row
        // both key to 0, and the ORG TOTAL is the one that must win regardless of row
        // order -- handleRange() now captures the rollup row's value in a local during
        // the loop and assigns $result[0] AFTER it, and explicitly skips any real kit 0
        // detail row rather than letting it overwrite (or be overwritten by) the total.
        // Kit 0's own count is not representable in this map -- latent today (kits.id is
        // a serial from 1), and documented on handleRange() for DEV-2468, which inherits
        // the same map.
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedScan(['ocr' => 'ZER0001', 'scanned_at' => $month->toDateTimeString(), 'kit_id' => 0]);
        $this->seedScan(['ocr' => 'ZER0002', 'scanned_at' => $month->copy()->addHours(1)->toDateTimeString(), 'kit_id' => 0]);
        $this->seedScan(['ocr' => 'ZER0003', 'scanned_at' => $month->copy()->addHours(2)->toDateTimeString(), 'kit_id' => 7]);

        $result = ScanPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => $month->copy()->startOfMonth()->toDateTimeString(),
            'end' => $month->copy()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertSame(3, $result['totals'][0], 'key 0 must carry the org total, never kit 0 alone');
        $this->assertSame(1, $result['totals'][7]);
        $this->assertCount(2, $result['totals'], 'only the org-total sentinel and kit 7 may appear -- the discarded real-kit-0 row must not leak a third entry');
    }

    public function test_handle_range_zero_fills_when_no_rows_match(): void
    {
        $result = ScanPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertSame(['totals' => [0 => 0], 'names' => []], $result);
    }

    public function test_an_unlisted_timezone_still_reaches_clickhouse_as_a_valid_zone(): void
    {
        // ClickHouse REJECTS a non-IANA zone outright -- verified against the local
        // container: toTimeZone(now(), 'CST') => Code 36, BAD_ARGUMENTS. So this test fails
        // LOUDLY if safeTimezone() is ever removed from the SQL interpolation, which is the
        // behavioural barrier the RN4 tautology failed to provide. CST is PHP-valid and
        // absent from ALL_WITH_BC, and reaches us via the unvalidated users.dti blob.
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);
        $this->seedScan(['ocr' => 'TZU0001', 'scanned_at' => $month->toDateTimeString()]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonth()->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'CST',
        ]);

        $this->assertNotEmpty($result, 'an unlisted zone must be allowlisted to UTC before it reaches SQL');
        $this->assertSame(1, array_sum($result));
    }

    public function test_handle_emits_no_month_bucket_outside_the_requested_window(): void
    {
        // R3, the residual Oscar's definition turned from a style question into a
        // correctness one: "unique by reporting window/timeframe" makes the window
        // authoritative, so no row inside it may go uncounted.
        //
        // This is the behavioural barrier handle() previously lacked entirely -- the
        // withdrawn shape converted bounds with the RAW zone while grouping in the
        // ALLOWLISTED one, so for CST the window ran to 05:59 UTC on the 1st while the
        // grouping keyed those rows to the NEXT month. UniqueScanCounts::uncachedPastMonths()
        // has no such key, so it dropped them and cached the short bucket for a year.
        //
        // Seeded either side of the boundary: the in-window row must be counted, and the
        // row 3h into the next UTC month must not appear at all. Under the withdrawn split
        // the second row falls inside the shifted window and produces an orphan bucket.
        $monthStart = now()->startOfMonth();

        $this->seedScan([
            'ocr' => 'TZW0001',
            'scanned_at' => $monthStart->copy()->addDays(5)->setTime(12, 0, 0)->toDateTimeString(),
        ]);
        $this->seedScan([
            'ocr' => 'TZW0002',
            'scanned_at' => $monthStart->copy()->addMonth()->setTime(3, 0, 0)->toDateTimeString(),
        ]);

        $result = ScanPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => $monthStart->toDateTimeString(),
            'end' => $monthStart->copy()->endOfMonth()->toDateTimeString(),
            'timezone' => 'CST',
        ]);

        $this->assertSame(
            [$monthStart->format('Y-m')],
            array_keys($result),
            'every returned bucket must lie inside the requested window; an orphan key is a row the caller will silently drop'
        );
        $this->assertSame(1, $result[$monthStart->format('Y-m')]);
    }

    public function test_handle_range_honours_the_callers_zone_verbatim(): void
    {
        // handleRange() interpolates NO timezone into SQL, so it must convert the caller's
        // bounds with the zone it was handed and never sanitise. Its callers depend on the
        // difference: getRangeTotal() passes the real viewer zone, because a user asking for
        // "Today" means their actual local day.
        //
        // An earlier revision normalised here. For a CST viewer that turned "Today" into
        // 18:00 yesterday -> 17:59 today. This test is the barrier against that returning.
        //
        // The scan sits at 03:00 UTC, which is 21:00 the PREVIOUS day in CST (UTC-6). Asking
        // for the CST day that CONTAINS 21:00 must find it; reading those same wall-clock
        // bounds as UTC lands six hours earlier and misses it.
        $utcInstant = now()->startOfMonth()->addDays(10)->setTime(3, 0, 0);
        $this->seedScan(['ocr' => 'TZR0001', 'scanned_at' => $utcInstant->toDateTimeString()]);

        $cstDay = $utcInstant->copy()->subHours(6)->format('Y-m-d');

        $found = ScanPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => $cstDay.' 00:00:00',
            'end' => $cstDay.' 23:59:59',
            'timezone' => 'CST',
        ]);

        $this->assertSame(
            1,
            $found['totals'][0] ?? 0,
            'the CST-local day containing the scan must include it -- sanitising the zone to UTC misses it'
        );

        // Control: the identical wall-clock window declared as UTC must NOT find it. Without
        // this the assertion above would pass even if the zone were ignored entirely.
        $missed = ScanPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => $cstDay.' 00:00:00',
            'end' => $cstDay.' 23:59:59',
            'timezone' => 'UTC',
        ]);

        $this->assertSame(
            0,
            $missed['totals'][0] ?? 0,
            'the same wall-clock window read as UTC must miss the scan -- otherwise the zone is inert'
        );
    }

    private function seedScan(array $overrides = []): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.scans_cold')->insert(array_merge([
            'id' => $this->scanSeq++,
            'kit_id' => 1,
            'org_id' => self::ORG_ID,
            'ocr' => 'ABC1234',
            'us_state' => 'TX',
            'camera_name' => 'CAM-1',
            'scanned_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'lng' => -97.7,
            'lat' => 30.3,
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ], $overrides));
    }
}
