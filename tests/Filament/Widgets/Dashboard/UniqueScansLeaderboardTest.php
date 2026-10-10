<?php

declare(strict_types=1);

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\UniqueScansLeaderboard;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class UniqueScansLeaderboardTest extends TestCase
{
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

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_card_key(): void
    {
        $this->assertSame('unique-scans-leaderboard', (new UniqueScansLeaderboard)->getCardKey());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_headlines_the_leader_count_with_success_colour_and_a_thirteen_point_chart(): void
    {
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedRangeRollup($orgId, 'this_month', [
            'totals' => [0 => 130, 11 => 90, 12 => 40],
            'names' => [11 => 'Kit Alpha', 12 => 'Kit Bravo'],
        ]);
        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        $stat = $widget->toStat();

        $this->assertSame('90', $stat->getValue(), 'the headline is the LEADER\'s own count, never the org total');
        $this->assertSame('success', $stat->getColor());
        $this->assertCount(13, $stat->getChart());
    }

    /**
     * DEV-2586 (review-2): this card's description moved from a plain string to the shared
     * {@see RendersDashboardStat::leaderKitDescription()} HTML. It was the only one of the three
     * leaderboard cards with NO PHPUnit assertion on its description, so a regression in that
     * helper would have been caught for two cards and shipped for this one. The truncate class
     * is the DEV-2324 height-parity guard; the title carries the untruncated name.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_names_the_leading_kit_on_one_truncated_line(): void
    {
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedRangeRollup($orgId, 'this_month', [
            'totals' => [0 => 130, 11 => 90, 12 => 40],
            'names' => [11 => 'Kit Alpha', 12 => 'Kit Bravo'],
        ]);
        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        $description = (string) $widget->toStat()->getDescription();

        $this->assertStringContainsString('Top Kit: Kit Alpha', $description);
        $this->assertStringContainsString('truncate', $description);
        $this->assertStringContainsString('title="Kit Alpha"', $description);
        $this->assertStringNotContainsString('Kit Bravo', $description);
    }

    /**
     * The description is HTML now, so a kit name carrying markup must be escaped, not rendered.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_escapes_a_kit_name_containing_markup(): void
    {
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedRangeRollup($orgId, 'this_month', [
            'totals' => [0 => 130, 11 => 90],
            'names' => [11 => '<script>alert(1)</script>'],
        ]);
        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        $description = (string) $widget->toStat()->getDescription();

        $this->assertStringNotContainsString('<script>', $description);
        $this->assertStringContainsString('&lt;script&gt;', $description);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_leaderboard_never_includes_the_org_total_rollup_row(): void
    {
        // AC-2: key 0 is the WITH ROLLUP org total, not a kit -- it must never appear as a
        // ranked entry, and the leader/runner-up must come from the per-kit rows only.
        $user = $this->actingAsProUser();
        $orgId = $user->org_id;

        $this->seedRangeRollup($orgId, 'this_month', [
            'totals' => [0 => 999999, 5 => 30, 6 => 20],
            'names' => [5 => 'Kit Five', 6 => 'Kit Six'],
        ]);

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        $leaderboard = $widget->leaderboardForSelectedRange();

        $ids = \array_column($leaderboard, 'id');
        $totals = \array_column($leaderboard, 'total');

        $this->assertNotContains(0, $ids, 'the org-total rollup row must never appear as a ranked kit');
        $this->assertNotContains(999999, $totals, 'the org total must never leak into a per-kit total');
        $this->assertCount(2, $leaderboard);
        $this->assertSame(30, $leaderboard[0]['total']);
        $this->assertSame('Kit Five', $leaderboard[0]['name']);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_leaderboard_caps_at_ten_with_the_kit_id_tiebreak(): void
    {
        $user = $this->actingAsProUser();
        $orgId = $user->org_id;

        // 12 kits, all tied at 10 -- only kit_id ASC can order them, and only the first
        // ten (by that tiebreak) may survive the cap. `handleRange()` always populates a
        // `names` entry alongside every `totals` entry (they are written together, in the
        // same branch -- see its docblock), so the fixture mirrors that invariant here.
        $totals = [0 => 500];
        $names = [];
        for ($kitId = 20; $kitId >= 9; $kitId--) {
            $totals[$kitId] = 10;
            $names[$kitId] = "Kit {$kitId}";
        }

        $this->seedRangeRollup($orgId, 'this_month', ['totals' => $totals, 'names' => $names]);

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        $leaderboard = $widget->leaderboardForSelectedRange();

        $this->assertCount(10, $leaderboard, 'the ranking must cap at 10');
        $this->assertSame(\range(9, 18), \array_column($leaderboard, 'id'), 'ties must break on kit_id ASC');
    }

    /**
     * AC-2's mutation-critical assertion: the sparkline must equal the shared org-total
     * monthly series, NOT a sum of this card's own leaderboard rows. The fixture makes the
     * two provably different -- the seeded per-kit rollup for the target window sums to
     * 40, far below the seeded shared org-total series value (777) for that same month --
     * so a regression to the inherited array_sum() default fails this assertion. Verified
     * by mutation: temporarily removing the chartSeries() override makes this test fail.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_chart_series_reads_the_shared_month_keys_not_a_sum_of_the_leaderboard(): void
    {
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $targetMonthKey = now(tz: $timezone)->startOfMonth()->subMonths(3)->format('Y-m');
        cache()->put(
            ScanPlateStatsBatchQuery::sharedMonthCacheKey($orgId, $targetMonthKey, $timezone),
            777,
            now()->addYear()
        );

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        $chart = (new ReflectionMethod($widget, 'chartSeries'))->invoke($widget);

        $index = 12 - 3;

        $this->assertSame(777.0, $chart[$index], 'the sparkline must read the shared org-total series');
        $this->assertCount(13, $chart);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_chart_series_open_month_reads_the_y_m_key(): void
    {
        $user = $this->actingAsProUser();
        $timezone = ScanPlateStatsBatchQuery::safeTimezone($user->timezone ?? 'UTC');
        $openMonth = now(tz: $timezone)->format('Y-m');

        cache()->put(
            ScanPlateStatsBatchQuery::sharedMonthCacheKey($user->org_id, $openMonth, $timezone),
            321,
            now()->addMinutes(10)
        );

        $widget = new UniqueScansLeaderboard;
        $chart = (new ReflectionMethod($widget, 'chartSeries'))->invoke($widget);

        $this->assertSame(321.0, $chart[12]);
        $this->assertStringNotContainsString('this_month', $openMonth);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_fetch_range_rollup_reads_the_shared_range_cache_key_unique_scan_counts_writes(): void
    {
        // AC-4/AC-5: this drives the REAL (non-overridden) fetchRangeRollup() -- the seam
        // every other test here overrides wholesale. Seeding a sentinel a real ClickHouse
        // scan could never produce proves the method reads the shared key rather than
        // issuing its own handleRange() call: cache()->remember() only invokes its closure
        // on a miss, so a hit here is proof no new round trip happened.
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $start = '2026-08-01 00:00:00';
        $end = '2026-08-01 23:59:59';

        $sharedKey = ScanPlateStatsBatchQuery::sharedRangeCacheKey($orgId, $start, $end, $timezone);
        $sentinel = ['totals' => [0 => 999999, 7 => 12345], 'names' => [7 => 'Sentinel Kit']];
        cache()->put($sharedKey, $sentinel, now()->addMinute());

        $widget = new UniqueScansLeaderboard;
        $method = new ReflectionMethod($widget, 'fetchRangeRollup');

        $result = $method->invoke($widget, $orgId, $start, $end, $timezone);

        $this->assertSame($sentinel, $result, 'fetchRangeRollup() must read under ScanPlateStatsBatchQuery::sharedRangeCacheKey()');
    }

    /**
     * DEV-2468 S-A/S-E: `this_month` must resolve through the SANITISED bucket zone --
     * the same {@see ScanPlateStatsBatchQuery::currentMonthWindow()} call
     * UniqueScanCounts::resolveCurrentMonthFromCold() makes -- or the two cards silently
     * scan `clarity.scans_cold` independently for any viewer whose zone is not in
     * `safeTimezone()`'s allowlist. `CST` is PHP-valid but unlisted, per that method's
     * own docblock -- exactly the live population this pins.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_this_month_resolves_the_range_key_with_the_bucket_zone_on_an_unlisted_viewer_zone(): void
    {
        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => 'CST']);
        $user->save();
        $this->actingAs($user->fresh());

        $orgId = $user->org_id;

        $this->assertSame('CST', auth()->user()->timezone, 'the fixture must actually be on an unlisted zone');

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        [$start, $end, $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);

        $this->assertSame('UTC', $timezone, 'CST is unlisted, so safeTimezone() must sanitise it to UTC');

        $expectedWindow = ScanPlateStatsBatchQuery::currentMonthWindow('UTC');
        $this->assertSame($expectedWindow['start'], $start);
        $this->assertSame($expectedWindow['end'], $end);

        // And it must be the SAME key UniqueScanCounts::resolveCurrentMonthFromCold()
        // writes for this org+zone -- proving the sharing seam actually closes.
        $this->seedRangeRollup($orgId, 'this_month', ['totals' => [0 => 5, 9 => 5], 'names' => [9 => 'Kit Nine']]);
        $leaderboard = $widget->leaderboardForSelectedRange();
        $this->assertSame([['id' => 9, 'name' => 'Kit Nine', 'total' => 5]], $leaderboard);
    }

    /**
     * Day arms share {@see BuildsDateFilterChartSeries::viewerTimezone()} with
     * UniqueScanCounts::getRangeTotal(). `toUtcDatetime()` parses that zone, and a raw
     * `NotAReal/Zone` throws. `CST` follows `safeTimezone()` to UTC, same as the month arms.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_today_and_last_7d_resolve_the_range_key_with_the_sanitised_viewer_zone(): void
    {
        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => 'CST']);
        $user->save();
        $this->actingAs($user->fresh());

        foreach (['today', 'last_7d'] as $date) {
            $widget = new UniqueScansLeaderboard;
            $widget->date = $date;

            [, , $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);

            $this->assertSame('UTC', $timezone, "[{$date}] must sanitise CST through safeTimezone(), matching getRangeTotal()");
        }
    }

    /**
     * DEV-2468 S-B + R3: `last_month` is the one arm UniqueScanCounts never warms a RANGE
     * key for -- it reads Last Month straight off its bucket-zone monthly series, never a
     * per-kit rollup. That is exactly why this arm must resolve on the BUCKET zone: the
     * month the sibling card DISPLAYS is a bucket-zone month, so resolving the raw zone
     * here let the two cards name different months side by side for an unlisted viewer
     * zone. An earlier revision asserted only key INEQUALITY on `America/Chicago`, which
     * is true in the defective case and therefore blessed the divergence.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_last_month_resolves_the_bucket_zone_window_matching_the_month_unique_scan_counts_displays(): void
    {
        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => 'CST']);
        $user->save();
        $this->actingAs($user->fresh());

        $orgId = $user->org_id;

        $this->assertSame('CST', auth()->user()->timezone, 'the fixture must actually be on an unlisted zone');

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'last_month';

        [$start, $end, $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);

        // last_month is month-granularity, so it belongs on the BUCKET zone exactly like
        // this_month -- DashboardWidget::getCardTotal() routes BOTH of them to the
        // bucket-zone 13-month series, so the month UniqueScanCounts DISPLAYS for Last
        // Month is a bucket-zone month. Resolving the raw zone here would let the two
        // cards name different months side by side for any unlisted zone.
        $this->assertSame('UTC', $timezone, 'CST is unlisted, so last_month must sanitise to UTC like this_month does');

        // LITERAL pins, not $expected = lastMonthWindow(...) -- comparing the method to
        // itself would pass against any wrong formula inside it. The class freezes the
        // clock at 2026-08-15, so the previous whole calendar month in UTC is July 2026.
        $this->assertSame('2026-07-01 00:00:00', $start);
        $this->assertSame('2026-07-31 23:59:59', $end);

        // It must still be a DIFFERENT window from this_month -- sharing the zone must not
        // collapse the two arms onto one key.
        $thisMonth = new UniqueScansLeaderboard;
        $thisMonth->date = 'this_month';
        [$tmStart, $tmEnd, $tmZone] = (new ReflectionMethod($thisMonth, 'resolveRangeAndTimezone'))->invoke($thisMonth);

        $this->assertNotSame(
            ScanPlateStatsBatchQuery::sharedRangeCacheKey($orgId, $start, $end, $timezone),
            ScanPlateStatsBatchQuery::sharedRangeCacheKey($orgId, $tmStart, $tmEnd, $tmZone),
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_fully_cold_render_calls_fetch_range_rollup_exactly_once(): void
    {
        $this->actingAsProUser();

        $widget = new CountingUniqueScansLeaderboard;
        $widget->date = 'this_month';

        $widget->toStat();

        $this->assertSame(1, $widget->rangeRollupCalls, 'toStat() must resolve the ranking through ONE seam call');
    }

    /**
     * The Pro gate is GONE -- `FeatureEnum::COMPANY_METRICS` no longer exists. Pointed the
     * other way on purpose: this is what fails if a gate is re-added to this card.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_can_view_card_data_needs_no_entitlement(): void
    {
        $this->assertTrue(auth()->guest());
        $this->assertTrue(UniqueScansLeaderboard::canViewCardData());

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $this->assertTrue(UniqueScansLeaderboard::canViewCardData());

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);
        $this->assertTrue(UniqueScansLeaderboard::canViewCardData());
    }

    /**
     * The zero-cost guarantee behind lockedStat(): a withheld card must not COMPUTE the
     * metric. Re-pointed from a non-entitled org to a ROLE-LESS viewer, which is the only
     * way a card is withheld now that the Pro gate is gone -- the guarantee itself is
     * unchanged and is the reason canViewCardData()/lockedStat() were kept.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_withheld_card_renders_locked_and_issues_zero_clickhouse_calls(): void
    {
        $this->actingAs($this->createUser(permissions: []));

        $this->assertFalse(UniqueScansLeaderboard::canViewCardData());

        $overview = new OverviewWithCountingUniqueScansLeaderboard;

        $method = new ReflectionMethod($overview, 'getStats');
        $method->setAccessible(true);

        /** @var array<Stat> $stats */
        $stats = $method->invoke($overview);

        $this->assertCount(1, $stats);

        $locked = $stats[0];
        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $locked->getValue());
        $this->assertNull($locked->getChart());

        $widget = $overview->capturedWidget;
        $this->assertInstanceOf(CountingUniqueScansLeaderboard::class, $widget);
        $this->assertSame(0, $widget->rangeRollupCalls);
    }

    /**
     * DEV-2651 t6: the card's five user-facing copy sites (heading, chart subtitle, axis
     * title, dataset label, description) must all drop "unique" -- the class name, card
     * key, and cache namespace are untouched, pinned separately below.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_word_unique_is_dropped_from_every_rendered_copy_site(): void
    {
        $user = $this->actingAsProUser();
        $orgId = $user->org_id;

        $this->seedRangeRollup($orgId, 'this_month', [
            'totals' => [0 => 130, 11 => 90, 12 => 40],
            'names' => [11 => 'Kit Alpha', 12 => 'Kit Bravo'],
        ]);

        $widget = new UniqueScansLeaderboard;
        $widget->date = 'this_month';

        $heading = (string) $widget->getHeading();
        $options = (string) (new ReflectionMethod($widget, 'getOptions'))->invoke($widget);
        $datasetLabel = $widget->getData()['datasets'][0]['label'];
        $description = (string) $widget->getDescription();

        $this->assertStringNotContainsStringIgnoringCase('unique', $heading);
        $this->assertStringNotContainsStringIgnoringCase('unique', $options, 'the chart subtitle and axis title live in getOptions()');
        $this->assertStringNotContainsStringIgnoringCase('unique', $datasetLabel);
        $this->assertStringNotContainsStringIgnoringCase('unique', $description);

        $this->assertSame('unique-scans-leaderboard', $widget->getCardKey(), 'the card key must survive the copy rename');
    }

    private function actingAsProUser(): User
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeUserPro($user);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Seeds under the EXACT key `leaderboardForSelectedRange()` will read -- derived via
     * `resolveRangeAndTimezone()` itself (not re-derived here) so this helper cannot drift
     * from the real per-arm zone convention it is proving (DEV-2468 S-A/S-E).
     *
     * @param  array{totals: array<int, int>, names: array<int, string>}  $rollup
     */
    private function seedRangeRollup(int $orgId, string $date, array $rollup): void
    {
        $widget = new UniqueScansLeaderboard;
        $widget->date = $date;

        [$start, $end, $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);

        $key = ScanPlateStatsBatchQuery::sharedRangeCacheKey($orgId, $start, $end, $timezone);

        cache()->put($key, $rollup, now()->addMinute());
    }

    private function seedAllSharedMonthsZero(int $orgId, string $timezone): void
    {
        for ($i = 12; $i >= 0; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');

            cache()->put(ScanPlateStatsBatchQuery::sharedMonthCacheKey($orgId, $monthKey, $timezone), 0, now()->addYear());
        }
    }
}

/**
 * Counts the widget's single ClickHouse seam so AC-4/AC-5 can be pinned deterministically.
 * Returns a canned result: the assertions are about HOW MANY trips happen, not their content.
 */
class CountingUniqueScansLeaderboard extends UniqueScansLeaderboard
{
    public int $rangeRollupCalls = 0;

    /** @var array{totals: array<int, int>, names: array<int, string>} */
    public array $rangeRollupResult = ['totals' => [0 => 0], 'names' => []];

    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeRollupCalls++;

        return $this->rangeRollupResult;
    }
}

class OverviewWithCountingUniqueScansLeaderboard extends CompanyMetricsOverview
{
    public ?CountingUniqueScansLeaderboard $capturedWidget = null;

    public static function cards(): array
    {
        return ['unique-scans-leaderboard' => CountingUniqueScansLeaderboard::class];
    }

    protected function makeMetricWidget(string $class): DashboardMetricCard
    {
        $widget = parent::makeMetricWidget($class);

        if ($widget instanceof CountingUniqueScansLeaderboard) {
            $this->capturedWidget = $widget;
        }

        return $widget;
    }
}
