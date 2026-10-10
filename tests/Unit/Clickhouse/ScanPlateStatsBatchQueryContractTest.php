<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Filament\Widgets\Dashboard\UniqueScanCounts;
use App\Filament\Widgets\Dashboard\UniqueScansLeaderboard;
use Carbon\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * S7: these three guards read source text or call pure, ClickHouse-free logic -- they
 * need no ClickHouse connection at all. They used to live in
 * Tests\Feature\Clickhouse\ScanPlateStatsBatchQueryTest, which `use`s RequiresClickhouse
 * at the class level (via setUp()); that trait skips EVERY method in the class whenever
 * CLICKHOUSE_DRIVER != clickhouse, so these three invariant guards -- the two this
 * ticket's review cared most about (GROUPING vs row order, and the class-neutral shared
 * cache key) -- were invisible on a normal local pgsql-bridge run. A sibling class with
 * no RequiresClickhouse usage runs them unconditionally.
 *
 * MUTATION-VERIFY EVERY TEST ADDED HERE, without exception. Eight tests on this ticket have
 * shipped or been drafted unable to fail; five were caught only by explicitly restoring the
 * defect and confirming the test goes red. Two traps recur, both worth knowing before adding
 * anything: a source-text assertion pins a SPELLING and any rename defeats it; and a harness
 * that does not assert its own mutation actually applied will report working tests as dead
 * (a shell-interpolated `$timezone` did exactly that here).
 *
 * The pure-tests-over-greps refactor that would end this defect class is deliberately OUT OF
 * SCOPE for DEV-2467 -- see test_handle_sanitises_its_zone_and_handle_range_does_not().
 */
#[Group('clickhouse')]
class ScanPlateStatsBatchQueryContractTest extends TestCase
{
    /**
     * Frozen for the same reason UniqueScanCountsTest:30-33 and UniqueScansLeaderboardTest
     * freeze it, and this file needs it MORE than either: the shared-key contract test
     * below compares two INDEPENDENTLY derived `currentMonthWindow()` reads, each of which
     * calls `now()` twice, with a widget path in between that calls it ~25 more times. On
     * an unfrozen clock a run that straddles a bucket-zone month boundary fails with no
     * code defect -- a green branch goes red and the next engineer chases a phantom
     * regression.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_handle_range_marks_the_rollup_row_with_grouping_not_row_position(): void
    {
        // WITH ROLLUP marks its total row by setting the grouping column to its DEFAULT,
        // which for Int32 kit_id is 0 -- so a REAL kit 0 makes the result carry two rows
        // keyed 0. Which of them lands in key 0 must not depend on row order, because
        // ClickHouse guarantees none without an ORDER BY.
        //
        // This asserts the SQL rather than the outcome, and that is deliberate: today the
        // rollup row happens to come last on this engine version, so the behavioural result
        // is IDENTICAL with or without GROUPING and a behavioural test here would be a
        // tautology that passes against the very mutation it is meant to catch (verified).
        // DB::listen never fires for the ClickHouse driver, so the heredocs are read
        // directly, matching test_emitted_sql_contains_neither_a_hardcoded_clarity_prefix_nor_final().
        $source = file_get_contents(app_path('Clickhouse/Queries/ScanPlateStatsBatchQuery.php'));

        preg_match_all('/<<<SQL(.*?)^\s*SQL;/ms', $source, $matches);
        $sql = implode("\n", $matches[1]);

        $this->assertStringContainsString('WITH ROLLUP', $sql);
        $this->assertStringContainsString(
            'GROUPING(kit_id)',
            $sql,
            'the rollup row must be identified by GROUPING(kit_id), not by kit_id = 0 landing last'
        );
    }

    public function test_emitted_sql_contains_neither_a_hardcoded_clarity_prefix_nor_final(): void
    {
        // DB::listen never fires for the ClickHouse driver, so the emitted SQL is asserted
        // against the SQL heredocs directly (not the whole file, whose docblocks legitimately
        // discuss both "FINAL" and "clarity." in prose). static::clarityDb() is permitted to
        // produce the literal string "clarity" at runtime -- what must never appear is a
        // hardcoded `clarity.` prefix baked into the SQL template itself.
        $source = file_get_contents(app_path('Clickhouse/Queries/ScanPlateStatsBatchQuery.php'));

        $this->assertGreaterThanOrEqual(
            2,
            preg_match_all('/<<<SQL(.*?)^\s*SQL;/ms', $source, $matches),
            'expected the SQL heredocs for handle(), handleRange(), and their shared FROM/WHERE'
        );

        $sql = implode("\n", $matches[1]);

        $this->assertStringNotContainsStringIgnoringCase('FINAL', $sql);
        $this->assertStringNotContainsString('FROM clarity.', $sql);
        $this->assertStringContainsString('{$clarityDb}', $sql);
    }

    public function test_shared_range_cache_key_is_class_neutral(): void
    {
        $key = ScanPlateStatsBatchQuery::sharedRangeCacheKey(
            742,
            '2026-08-01 00:00:00',
            '2026-08-31 23:59:59',
            'America/Chicago'
        );

        $this->assertStringNotContainsStringIgnoringCase('UniqueScanCounts', $key);
        $this->assertStringNotContainsStringIgnoringCase('DashboardWidget', $key);
        $this->assertSame(
            'scan-plate-stats:unique-range-v2:org=742:2026-08-0100:00:00:2026-08-3123:59:59:tz=America/Chicago',
            $key
        );
    }

    /**
     * AC-4/AC-5's whole premise: UniqueScanCounts and DEV-2468's leaderboard must resolve
     * to the SAME (start, end, timezone) triple for `this_month`, or both cards miss the
     * cache and each issue their own scan against `clarity.scans_cold` (DEV-2468 S-A).
     *
     * This drives the TWO REAL classes -- not a comparison of one function to itself,
     * which is what shipped here before and could not fail under any mutation. Both
     * widgets' `fetchRangeRollup()`/`fetchMonthlySeries()` seams are overridden to record
     * their arguments rather than call ClickHouse, so this needs no ClickHouse connection,
     * matching this file's stated purpose.
     *
     * MUTATION-VERIFIED: reverting `UniqueScansLeaderboard::resolveRangeAndTimezone()`'s
     * `this_month` arm to the raw viewer zone (the pre-fix shape) fails this assertion for
     * the `CST` fixture below -- `CST` is PHP-valid but absent from `safeTimezone()`'s
     * `ALL_WITH_BC` allowlist (see that method's own docblock), so the two keys diverge.
     */
    public function test_unique_scan_counts_and_the_leaderboard_share_the_this_month_key_on_an_unlisted_viewer_zone(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => 'CST']);
        $user->save();
        $this->actingAs($user->fresh());

        $this->assertSame('CST', auth()->user()->timezone, 'the fixture must actually be on an unlisted zone');

        $counts = new RecordingUniqueScanCountsForSharedKeyContract;
        (new ReflectionMethod($counts, 'getMonthlyData'))->invoke($counts, false);

        $leaderboard = new RecordingUniqueScansLeaderboardForSharedKeyContract;
        $leaderboard->date = 'this_month';
        $leaderboard->leaderboardForSelectedRange();

        $this->assertNotEmpty($counts->rangeRollupCalls, 'UniqueScanCounts must warm the this_month range key');
        $this->assertCount(1, $leaderboard->rangeRollupCalls);

        $countsCall = $counts->rangeRollupCalls[array_key_last($counts->rangeRollupCalls)];
        $leaderboardCall = $leaderboard->rangeRollupCalls[0];

        $countsKey = ScanPlateStatsBatchQuery::sharedRangeCacheKey(
            $user->org_id,
            $countsCall['start'],
            $countsCall['end'],
            $countsCall['timezone']
        );
        $leaderboardKey = ScanPlateStatsBatchQuery::sharedRangeCacheKey(
            $user->org_id,
            $leaderboardCall['start'],
            $leaderboardCall['end'],
            $leaderboardCall['timezone']
        );

        $this->assertSame(
            $countsKey,
            $leaderboardKey,
            'UniqueScanCounts and the leaderboard must resolve the SAME shared range key for this_month'
        );
    }

    /**
     * L-3: sharedMonthCacheKey() had no literal-pin contract test and no version suffix,
     * unlike its sibling sharedRangeCacheKey() -- a rename or format change would silently
     * orphan a year of past-month buckets for both cards with nothing failing.
     */
    public function test_shared_month_cache_key_is_class_neutral_and_versioned(): void
    {
        $key = ScanPlateStatsBatchQuery::sharedMonthCacheKey(742, '2026-08', 'America/Chicago');

        $this->assertStringNotContainsStringIgnoringCase('UniqueScanCounts', $key);
        $this->assertStringNotContainsStringIgnoringCase('UniqueScansLeaderboard', $key);
        $this->assertSame(
            'scan-plate-stats:unique-month-v1:org=742:2026-08:tz=America/Chicago',
            $key
        );
    }

    public function test_shared_range_cache_key_rejects_a_malformed_bound(): void
    {
        // LOW: sharedRangeCacheKey() is public and its collision-freedom depends entirely
        // on `$start`/`$end` being well-formed; it must validate them itself rather than
        // trusting every future caller.
        $this->expectException(\InvalidArgumentException::class);

        ScanPlateStatsBatchQuery::sharedRangeCacheKey(742, 'not-a-datetime', '2026-08-31 23:59:59', 'UTC');
    }

    public function test_safe_timezone_falls_back_to_utc_and_never_throws(): void
    {
        // The DEV-2441 contract (KitScanPathsQuery::safeTimezone): validate, then fall back
        // to UTC. An earlier version of this class THREW, and because users.dti is written
        // unvalidated by UserDtiController while CompanyMetricsOverview::getStats() has no
        // per-card guard, a PHP-valid-but-unlisted zone took down all ten dashboard cards.
        foreach (['Not/A_Real_Zone', "Not/AZone'--", 'CST', 'PST', '+05:00', ''] as $bad) {
            $this->assertSame(
                'UTC',
                ScanPlateStatsBatchQuery::safeTimezone($bad),
                "[{$bad}] must fall back to UTC, not throw"
            );
        }
    }

    public function test_safe_timezone_keeps_a_constructable_legacy_alias_and_canonicalises_an_unconstructable_one(): void
    {
        // PHP 8.4.23 still constructs US/Central, so the alias is kept (identity-keep).
        // PHP 8.4.25 drops it from ALL_WITH_BC and from constructability; returning the
        // alias then throws inside Carbon. The expected zone follows this process's
        // DateTimeZone, and the resolved zone must itself construct. Never UTC.
        foreach (['US/Central', 'US/Eastern', 'US/Pacific', 'Asia/Calcutta'] as $legacy) {
            $resolved = ScanPlateStatsBatchQuery::safeTimezone($legacy);

            $this->assertSame(self::expectedLegacyTimezone($legacy), $resolved);
            $this->assertNotSame('UTC', $resolved);
            new \DateTimeZone($resolved);
        }

        $this->assertSame('America/Chicago', ScanPlateStatsBatchQuery::safeTimezone('America/Chicago'));
    }

    public function test_handle_sql_converts_a_legacy_alias_without_an_invalid_timezone_exception(): void
    {
        $zone = self::expectedLegacyTimezone('US/Central');

        $sql = ScanPlateStatsBatchQuery::handleSql([
            'org_id' => 1,
            'start' => '2026-08-01 00:00:00',
            'end' => '2026-08-31 23:59:59',
            'timezone' => 'US/Central',
        ]);

        $this->assertStringContainsString("toTimeZone(scanned_at, '{$zone}')", $sql);

        $utc = Carbon::parse('2026-08-01 00:00:00', ScanPlateStatsBatchQuery::safeTimezone('US/Central'))
            ->utc()
            ->toDateTimeString();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utc);
    }

    /**
     * Alias when this PHP can construct it; otherwise the canonical IANA id.
     * A hardcoded alias expectation stays green on 8.4.23 and fails on 8.4.25.
     */
    private static function expectedLegacyTimezone(string $alias): string
    {
        $canonical = match ($alias) {
            'US/Central' => 'America/Chicago',
            'US/Eastern' => 'America/New_York',
            'US/Pacific' => 'America/Los_Angeles',
            'Asia/Calcutta' => 'Asia/Kolkata',
            default => throw new \InvalidArgumentException("Unknown legacy alias [{$alias}]"),
        };

        try {
            new \DateTimeZone($alias);

            return $alias;
        } catch (\DateInvalidTimeZoneException|\Exception) {
            return $canonical;
        }
    }

    public function test_handle_sanitises_its_zone_and_handle_range_does_not(): void
    {
        // KNOWN-WEAK, and deliberately left that way -- the fix is out of scope for DEV-2467.
        //
        // This is a source-text assertion. Source-text assertions are the single root cause of
        // every test on this ticket that could not fail (eight of them): they pin a SPELLING,
        // so any rename walks straight past them. Three independent review lanes defeated this
        // test's predecessor with three different renames. Assume this one is defeatable too.
        //
        // Do NOT patch it by adding more greps -- that is what produced the previous eight.
        // The real fix is to extract the bound/zone resolution into a pure function returning
        // (start, end, zone) and assert its RETURN VALUE: no ClickHouse needed, and renames
        // cannot defeat it. Until that lands, the barriers you can actually trust for this
        // invariant are the BEHAVIOURAL tests in Tests\Feature\Clickhouse\ScanPlateStatsBatchQueryTest
        // (mutation-verified), which run only when CLICKHOUSE_DRIVER=clickhouse -- so on a
        // default local run this invariant is effectively uncovered.
        //
        // The invariant: the window bounds and the month buckets must be cut with the SAME
        // zone. Split them and the grouping emits a month key outside the caller's write
        // set, whose rows are then silently discarded (RN5) -- see safeTimezone()'s docblock
        // for why this horn was chosen over the exact-window one (RN4).
        //
        // Asserted on the ASSIGNMENT, not on the call sites. The predecessor of this test
        // grepped the call sites only, and two review lanes proved by mutation that it
        // passed against the very defect it named: the call-site lines are byte-identical
        // whether the bound variable holds the raw zone or the sanitised one, because the
        // defect lives entirely in the assignment. Each assertion below was re-verified by
        // mutation (see the test class docblock).
        $source = file_get_contents(app_path('Clickhouse/Queries/ScanPlateStatsBatchQuery.php'));

        preg_match_all('/<<<SQL(.*?)^\s*SQL;/ms', $source, $matches);
        $sql = implode("\n", $matches[1]);

        $this->assertStringContainsString("toTimeZone(scanned_at, '{\$timezone}')", $sql);

        // A SECOND zone variable is still forbidden: that is how the withdrawn split
        // reintroduced two conventions in one method. The raw binding itself is now
        // legitimate in handleRange() -- the counts below pin which method may have it.
        $this->assertStringNotContainsString(
            '$sqlTimezone',
            $source,
            'a second zone variable reintroduces the split that discards rows at a month boundary'
        );

        // The ASYMMETRY is the invariant now: handle() sanitises (its grouping expression is
        // the bucket key and must be SQL-safe); handleRange() does not (it interpolates no
        // zone, and its filter callers need the viewer's real local day).
        $this->assertSame(
            1,
            substr_count($source, "\$timezone = self::safeTimezone((string) \$arguments['timezone']);"),
            'exactly one entry point -- handle() -- may sanitise the caller zone'
        );
        $this->assertSame(
            1,
            substr_count($source, "\$timezone = (string) \$arguments['timezone'];"),
            'handleRange() must take the caller zone verbatim'
        );
    }

    public function test_shared_range_cache_key_keys_on_the_zone_verbatim(): void
    {
        // The key must describe the query that actually ran. handleRange() uses the caller's
        // zone verbatim, so 'CST' and 'UTC' produce DIFFERENT windows and must not collide --
        // even though safeTimezone() would map one onto the other.
        //
        // Direction matters and is deliberate. Over-keying costs a cache miss; under-keying
        // serves one viewer another viewer's numbers. An earlier revision normalised here,
        // which was safe only while handleRange() also normalised; it no longer does.
        $args = [742, '2026-08-01 00:00:00', '2026-08-31 23:59:59'];

        $this->assertNotSame(
            ScanPlateStatsBatchQuery::sharedRangeCacheKey(...[...$args, 'UTC']),
            ScanPlateStatsBatchQuery::sharedRangeCacheKey(...[...$args, 'CST']),
            'CST and UTC resolve different windows in handleRange(), so they must not share a key'
        );

        $this->assertStringContainsString(
            ':tz=CST',
            ScanPlateStatsBatchQuery::sharedRangeCacheKey(...[...$args, 'CST']),
            'the key must carry the zone the caller actually queried with'
        );

        // The sharing seam still has to WORK: two callers naming the same org, window and
        // zone must land on one key. Asserted against a literal rather than against a second
        // call of the same function -- comparing f(x) to f(x) is true of any deterministic
        // implementation and cannot fail.
        $this->assertSame(
            'scan-plate-stats:unique-range-v2:org=742:2026-08-0100:00:00:2026-08-3123:59:59:tz=America/Chicago',
            ScanPlateStatsBatchQuery::sharedRangeCacheKey(...[...$args, 'America/Chicago']),
        );
    }
}

/**
 * Local to this file (not reused from UniqueScanCountsTest's `RecordingUniqueScanCounts`)
 * -- both test files live in the `tests/` PSR-4 root and a name collision between two
 * classes declared in different files is a fatal redeclaration, not a scoping error.
 *
 * @internal
 */
class RecordingUniqueScanCountsForSharedKeyContract extends UniqueScanCounts
{
    /** @var array<int, array{start: string, end: string, timezone: string}> */
    public array $rangeRollupCalls = [];

    protected function fetchMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        return [];
    }

    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeRollupCalls[] = compact('start', 'end', 'timezone');

        return ['totals' => [0 => 0], 'names' => []];
    }
}

/**
 * @internal
 */
class RecordingUniqueScansLeaderboardForSharedKeyContract extends UniqueScansLeaderboard
{
    /** @var array<int, array{start: string, end: string, timezone: string}> */
    public array $rangeRollupCalls = [];

    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeRollupCalls[] = compact('start', 'end', 'timezone');

        return ['totals' => [0 => 0], 'names' => []];
    }
}
