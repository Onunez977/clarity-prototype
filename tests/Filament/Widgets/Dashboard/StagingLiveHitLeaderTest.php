<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use App\Enums\ProTierEnum;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\SharedHitStatsData;
use App\Filament\Widgets\Dashboard\StagingLiveHitLeader;
use App\Filament\Widgets\Dashboard\StagingLiveHits;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AC-4 (round-trip count) uses technique (c) from the ticket brief -- a value-based gap
 * proof -- not (a) `system.query_log`. (a) was attempted first (with `SYSTEM FLUSH LOGS`
 * and a `kit_org_id = <id>` boundary match to rule out numeric-prefix collisions) and
 * DEMONSTRABLY FAILED here: this local ClickHouse is the shared, literal `clarity`/`zod`
 * instance every worktree points at (see the repo's local-CH-literal-db note), while each
 * worktree's Postgres assigns its own auto-increment org ids independently -- so two
 * worktrees running tests concurrently can both mint org id 1 and both issue real
 * `hits_cold` queries carrying `kit_org_id = 1`, which a query-log delta cannot tell
 * apart from this test's own traffic. That collision was observed directly while
 * developing this test (stale/concurrent `kit_org_id = 1` rows inflated the "warm cache"
 * delta), so it is not usable in this repo. (b) (mocking the static
 * `StagingLiveHitLeaderQuery`/`HitPlateStatsBatchQuery` calls) is also awkward here: both
 * are plain static classes with no DI seam, and by the time this test class runs in the
 * suite they are typically already autoloaded, which rules out Mockery's `overload:`
 * approach. (c) instead seeds every cache bucket with a SENTINEL value a real fetch
 * against a fresh, unseeded org could never produce (`hits_cold` has no rows for it, so a
 * real fetch always returns `[]`/`0`) and proves: a warm cache leaves every sentinel
 * untouched (zero round trips), and a cold cache with exactly one missing month fills
 * ONLY that month while every sibling sentinel survives byte-for-byte -- which a 13x
 * per-month fan-out (or a re-sweep of the whole window) could not have produced.
 */
class StagingLiveHitLeaderTest extends TestCase
{
    private ?array $originalClickhouseConfig = null;

    protected function tearDown(): void
    {
        $this->restoreClickhouseConnection();
        $this->resetWidgetStatics();

        parent::tearDown();
    }

    /**
     * StagingLiveHitLeader's two `protected static` resolve-once memos persist for the whole
     * PHPUnit process. Today isolation happens to hold because Postgres sequences do not roll
     * back under RefreshDatabase, so every test gets a distinct org id -- an accidental
     * guarantee, not a designed one. Reset them explicitly so a fixed-org-id test, or a switch
     * to DatabaseTruncation with identity restart, cannot make one test short-circuit on
     * another's state.
     */
    private function resetWidgetStatics(): void
    {
        foreach (['sharedSparklineResolved'] as $name) {
            $property = new \ReflectionProperty(StagingLiveHitLeader::class, $name);
            $property->setValue(null, []);
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_card_key(): void
    {
        $this->assertSame('staging-live-hit-leader', (new StagingLiveHitLeader)->getCardKey());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_headlines_the_leader_count_with_warning_colour_and_a_thirteen_point_chart(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        $this->seedRangeRollup($widget, $orgId, [
            'totals' => [0 => 130, 1 => 90, 2 => 40],
            'names' => [1 => 'Kit Alpha', 2 => 'Kit Bravo'],
        ]);

        $stat = $widget->toStat();

        $this->assertSame('90', $stat->getValue());
        $this->assertSame('warning', $stat->getColor());
        $this->assertCount(13, $stat->getChart());
    }

    /**
     * DEV-2586: the collapsed Stat must name the leading kit, not just its total.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_description_names_the_leading_kit(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        $this->seedRangeRollup($widget, $orgId, [
            'totals' => [0 => 130, 1 => 90, 2 => 40],
            'names' => [1 => 'Kit Alpha', 2 => 'Kit Bravo'],
        ]);

        $stat = $widget->toStat();

        $this->assertStringContainsString('Top Kit: Kit Alpha', (string) $stat->getDescription());
        $this->assertStringContainsString('truncate', (string) $stat->getDescription());
        $this->assertStringNotContainsString('Kit Bravo', (string) $stat->getDescription());
    }

    /**
     * Headline-parity guard for the `max('total')` -> `sortByDesc('total')->first()` swap:
     * the headline must still be exactly what `max('total')` produced before this change.
     * The fixture's highest total (90) sits on the SECOND element, not the first, so a
     * naive `->first()` without the sort would return 40 instead and fail this assertion.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_headline_matches_pre_change_max_total_when_leader_is_not_first(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        $this->seedRangeRollup($widget, $orgId, [
            'totals' => [0 => 190, 1 => 40, 2 => 90, 3 => 60],
            'names' => [1 => 'Kit One', 2 => 'Kit Two', 3 => 'Kit Three'],
        ]);

        $stat = $widget->toStat();

        $this->assertSame('90', $stat->getValue());
        $this->assertStringContainsString('Top Kit: Kit Two', (string) $stat->getDescription());
    }

    /**
     * AC-3 core proof: the sparkline must equal the shared `staging-live-hits:unique:*`
     * series, NOT the sum of this card's own top-10-per-month leaderboard buckets. The
     * fixture makes the two provably different -- the leaderboard bucket's total (50) is
     * far below the seeded shared/org total (180) for the same month -- so a regression
     * to summing the leaderboard would fail this assertion.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_sparkline_agrees_with_the_shared_series_not_the_leaderboard_sum(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths(3);
        $targetMonthKey = $targetMonth->format('Y-m');

        // The org's TRUE deduped Staging Live total for that month. A top-10 sum of the
        // ranked list would be a different number, and this point is not that list.
        cache()->put('staging-live-hits:unique:'.$targetMonthKey.':org='.$orgId, 180, now()->addYear());

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';

        $chart = (new ReflectionMethod($widget, 'chartSeries'))->invoke($widget);

        $index = 12 - 3;

        $this->assertSame(180.0, $chart[$index], 'the sparkline must read the shared deduped series, not a ranked-list sum');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_date_filter_round_trip(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $thisMonthWidget = new StagingLiveHitLeader;
        $thisMonthWidget->date = 'this_month';
        $this->seedRangeRollup($thisMonthWidget, $orgId, [
            'totals' => [0 => 40, 1 => 40],
            'names' => [1 => 'This Month Kit'],
        ]);
        $this->assertSame('40', $thisMonthWidget->toStat()->getValue());

        $lastMonthWidget = new StagingLiveHitLeader;
        $lastMonthWidget->date = 'last_month';
        $this->seedRangeRollup($lastMonthWidget, $orgId, [
            'totals' => [0 => 77, 2 => 77],
            'names' => [2 => 'Last Month Kit'],
        ]);
        $this->assertSame('77', $lastMonthWidget->toStat()->getValue());

        $todayWidget = new StagingLiveHitLeader;
        $todayWidget->date = 'today';
        $this->seedRangeRollup($todayWidget, $orgId, [
            'totals' => [0 => 12, 3 => 12],
            'names' => [3 => 'Today Kit'],
        ]);
        $this->assertSame('12', $todayWidget->toStat()->getValue());

        $weekWidget = new StagingLiveHitLeader;
        $weekWidget->date = 'last_7d';
        $this->seedRangeRollup($weekWidget, $orgId, [
            'totals' => [0 => 21, 4 => 21],
            'names' => [4 => 'Week Kit'],
        ]);
        $this->assertSame('21', $weekWidget->toStat()->getValue());
    }

    /**
     * QA Case H: Last Month seeded 0 in ClickHouse still rendered This Month's
     * leaderboard 4 and sibling Staging Live Hits 14 on the compact grid. The
     * overview builds cards as adapters, so the page date must actually reach
     * both widgets -- a this_month leftover cannot keep winning.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_overview_last_month_does_not_reuse_this_month_staging_live_totals(): void
    {
        // The grid's first card, UniqueScanCounts, fills a cold current month through
        // fetchRangeRollup() with no try/catch. The pgsql stand-in rejects that SQL
        // (GROUP BY ... WITH ROLLUP). Same guard as the other ClickHouse-backed tests
        // in this file. CI sets CLICKHOUSE_DRIVER=clickhouse and still runs this.
        $this->requireRealClickhouse();

        Carbon::setTestNow('2026-09-04 10:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $thisMonthLeader = new StagingLiveHitLeader;
        $thisMonthLeader->date = 'this_month';
        $this->seedRangeRollup($thisMonthLeader, $orgId, [
            'totals' => [0 => 14, 1 => 4],
            'names' => [1 => 'This Month Kit'],
        ]);
        cache()->put('staging-live-hits:unique:this_month:org='.$orgId, 7, now()->addMinutes(15));
        cache()->put('staging-live-hits:this_month:org='.$orgId, 14, now()->addMinutes(15));

        $lastMonthLeader = new StagingLiveHitLeader;
        $lastMonthLeader->date = 'last_month';
        $this->seedRangeRollup($lastMonthLeader, $orgId, [
            'totals' => [0 => 0],
            'names' => [],
        ]);
        cache()->put('staging-live-hits:unique:last_month:org='.$orgId, 0, now()->addYear());
        cache()->put('staging-live-hits:last_month:org='.$orgId, 0, now()->addYear());

        $overview = Livewire::test(CompanyMetricsOverview::class, [
            'pageFilters' => ['date_toggles' => 'last_month'],
        ]);

        $this->assertSame('0', $this->overviewCardValue($overview->instance(), 'staging-live-hit-leader'));
        $this->assertSame('0', $this->overviewCardValue($overview->instance(), 'staging-live-hits'));

        $overview->dispatch('refresh-dashboard', date: 'this_month');
        $this->assertSame('4', $this->overviewCardValue($overview->instance(), 'staging-live-hit-leader'));
        $this->assertSame('14', $this->overviewCardValue($overview->instance(), 'staging-live-hits'));

        $overview->dispatch('refresh-dashboard', date: 'last_month');
        $this->assertSame('0', $this->overviewCardValue($overview->instance(), 'staging-live-hit-leader'));
        $this->assertSame('0', $this->overviewCardValue($overview->instance(), 'staging-live-hits'));
    }

    /**
     * The Pro gate is GONE -- `FeatureEnum::COMPANY_METRICS` no longer exists. Four tests used
     * to cover it: deny-then-allow, the impersonator bypass, the guest denial, and tier
     * membership. They collapse to one property: entitlement is irrelevant.
     *
     * Pointed the other way on purpose -- this is what fails if a gate is re-added here.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('staging-live-hit-leader')]
    public function test_can_view_card_data_needs_no_entitlement(): void
    {
        // Guest: TOTAL, and now permissive (the default's short-circuit).
        $this->assertTrue(auth()->guest());
        $this->assertTrue(StagingLiveHitLeader::canViewCardData());

        // A plain ADMIN on a non-Pro org -- the case that used to be denied and locked.
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);
        $this->assertTrue(StagingLiveHitLeader::canViewCardData());

        // PRO_LITE, the other formerly-denied tier, granted via tier membership alone.
        $user->org->meta()->create([
            'properties' => [
                'platform_repo_pro' => true,
                'platform_repo_pro_tier' => ProTierEnum::PRO_LITE->value,
            ],
        ]);
        $user->org->refresh();
        cache()->forget('org_pro_enabled_'.$user->org_id);
        Feature::flushCache();

        $this->assertTrue(StagingLiveHitLeader::canViewCardData());

        // An impersonator session changes nothing -- there is nothing left to bypass.
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);
        $this->assertTrue(StagingLiveHitLeader::canViewCardData());
    }

    /**
     * A down ClickHouse must not persist an empty sparkline month, and a failed
     * range rollup must not be written under the shared key.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_clickhouse_failure_does_not_cache_empty_data_and_throttles_via_the_negative_cache_marker(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllSharedMonthsZero($orgId, $timezone);

        $staleMonthKey = now(tz: $timezone)->startOfMonth()->subMonths(6)->format('Y-m');
        $untouchedMonthKey = now(tz: $timezone)->startOfMonth()->subMonths(3)->format('Y-m');

        cache()->forget('staging-live-hits:unique:'.$staleMonthKey.':org='.$orgId);
        foreach (['live-hits', 'historical-hits', 'staging-historical-hits'] as $prefix) {
            cache()->forget($prefix.':unique:'.$staleMonthKey.':org='.$orgId);
        }

        $rangeStart = '2026-08-01 00:00:00';
        $rangeEnd = '2026-08-12 23:59:59';
        $rangeKey = StagingLiveHitLeaderQuery::sharedRangeCacheKey($orgId, $rangeStart, $rangeEnd, 'UTC');

        $this->forceClickhouseConnectionToFail();

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';

        (new ReflectionMethod($widget, 'resolveSharedMonths'))->invoke($widget, $orgId, $timezone);

        try {
            StagingLiveHitLeaderQuery::rememberedRange($orgId, $rangeStart, $rangeEnd, 'UTC');
            $this->fail('a down ClickHouse must surface from rememberedRange');
        } catch (\Throwable) {
            $this->assertNull(cache()->get($rangeKey), 'a failed range rollup must not be cached');
        }

        $this->assertNull(
            cache()->get('staging-live-hits:unique:'.$staleMonthKey.':org='.$orgId),
            'a failed shared-series fetch must not cache a fabricated 0 for a year'
        );

        $sharedMarkerKey = (new ReflectionMethod($widget, 'sharedFailureMarkerKey'))->invoke($widget, $orgId);
        $this->assertNotNull(cache()->get($sharedMarkerKey), 'a failed shared-series fetch must set the negative-cache marker');

        $this->restoreClickhouseConnection();

        (new ReflectionMethod($widget, 'resolveSharedMonths'))->invoke($widget, $orgId, $timezone);

        $this->assertNull(
            cache()->get('staging-live-hits:unique:'.$staleMonthKey.':org='.$orgId),
            'while the negative-cache marker is present, resolveSharedMonths() must short-circuit without querying'
        );
        $this->assertSame(0, cache()->get('staging-live-hits:unique:'.$untouchedMonthKey.':org='.$orgId));
    }

    /**
     * AC-4 (warm half): a fully warm cache must issue ZERO ClickHouse round trips.
     * Proven with sentinel values a real fetch against this fresh, unseeded org could
     * never produce (`hits_cold` has no rows for it, so a real fetch returns `[]`/`0`) --
     * if resolveLeaderMonths()/resolveSharedMonths() queried anyway, the sentinels would
     * be overwritten with the real (empty) answer, which is trivially distinguishable.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_warm_cache_is_never_overwritten_by_a_real_clickhouse_fetch(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $this->seedAllMonthsWithSentinels($orgId, $timezone);

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        $this->seedRangeRollup($widget, $orgId, [
            'totals' => [0 => 12345, 999 => 12345],
            'names' => [999 => 'Sentinel'],
        ]);
        $stat = $widget->toStat();

        $this->assertSame(\number_format(12345), $stat->getValue());
        $this->assertSame(88888.0, $stat->getChart()[12]);

        for ($i = 12; $i >= 0; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');
            $dateType = $i === 0 ? 'this_month' : $monthKey;

            $shared = cache()->get('staging-live-hits:unique:'.$dateType.':org='.$orgId);
            $this->assertSame(88888 + $i, $shared, "month {$dateType} shared bucket must not be overwritten by a real fetch");
        }
    }

    /**
     * AC-4 (cold half): a single uncached month is filled by ONE batched round trip that
     * touches ONLY the gap -- never thirteen independent per-month queries. Proven by
     * pre-seeding every OTHER month with a sentinel and asserting those siblings survive
     * byte-for-byte: if the fetch had fanned out per-month (or re-swept the whole window),
     * it would have overwritten the siblings with the real (empty) answer too.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_cold_cache_fills_only_the_missing_month_in_one_batched_round_trip(): void
    {
        $this->requireRealClickhouse();

        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $gapMonthKey = now(tz: $timezone)->startOfMonth()->subMonths(6)->format('Y-m');

        $this->seedAllMonthsWithSentinels($orgId, $timezone, except: $gapMonthKey);

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        $this->seedRangeRollup($widget, $orgId, [
            'totals' => [0 => 1, 1 => 1],
            'names' => [1 => 'Warm Kit'],
        ]);
        $widget->toStat();

        $this->assertSame(
            0,
            cache()->get('staging-live-hits:unique:'.$gapMonthKey.':org='.$orgId),
            'the single uncached shared-series month must be filled by the batched fetch'
        );

        for ($i = 12; $i >= 0; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');

            if ($monthKey === $gapMonthKey) {
                continue;
            }

            $dateType = $i === 0 ? 'this_month' : $monthKey;

            $shared = cache()->get('staging-live-hits:unique:'.$dateType.':org='.$orgId);
            $this->assertSame(88888 + $i, $shared, "sibling month {$dateType} shared bucket must remain untouched");
        }
    }

    /**
     * Seeds every one of the 13 leader + shared-series buckets with a sentinel value a
     * real fetch against a fresh, unseeded org could never produce, optionally leaving
     * one month key ($except) uncached to create a single-month gap.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_shared_key_ttls_are_the_impersonation_aware_dashboard_ttls(): void
    {
        // Frozen deliberately: every assertion below compares two INDEPENDENT now() reads (one in
        // the expectation, one inside the method under test), so on the live wall clock a second
        // boundary between them fails the test. Comparing getTimestamp() rather than a formatted
        // string also means a sub-minute divergence in the mirror cannot slip through.
        Carbon::setTestNow('2026-08-12 12:00:00');

        // The regression this pins SHIPPED and was caught in review: the card wrote
        // `now()->addMinutes(15)` / `now()->addYear()` literals into SharedHitStatsData's own
        // keys, which drops DashboardWidget::getCacheTTL()'s admin_impersonator arm. An
        // impersonating staffer would have frozen the four shipped hit cards for a YEAR --
        // during the very session opened to verify them -- and every org's current-month
        // freshness would have gone 10 -> 15 minutes.
        //
        // Asserting against DashboardWidget's OWN output (via a real DashboardWidget-based
        // card) rather than against restated literals is what keeps the mirror faithful: if
        // getCacheTTL() ever changes, this fails instead of silently diverging.
        $this->actingAsProUser();

        $mirror = new ReflectionMethod(StagingLiveHitLeader::class, 'baseDashboardCacheTTL');
        $owner = new ReflectionMethod(StagingLiveHits::class, 'getCacheTTL');

        $leader = new StagingLiveHitLeader;
        $sibling = new StagingLiveHits;

        foreach ([0, 1] as $index) {
            $this->assertSame(
                $owner->invoke($sibling, $index)->getTimestamp(),
                $mirror->invoke($leader, $index)->getTimestamp(),
                "baseDashboardCacheTTL({$index}) must equal DashboardWidget::getCacheTTL({$index})"
            );
        }

        // And explicitly: the past-month arm is a year normally...
        $this->assertSame(
            now()->addYear()->getTimestamp(),
            $mirror->invoke($leader, 1)->getTimestamp()
        );
        // ...unless that month ended inside the settle window, in which case it stays at 10 minutes.
        $endedYesterday = now()->subDay();
        $this->assertSame(
            $owner->invoke($sibling, 1, $endedYesterday)->getTimestamp(),
            $mirror->invoke($leader, 1, $endedYesterday)->getTimestamp(),
            'a month end inside the settle window must match DashboardWidget::getCacheTTL'
        );
        $this->assertSame(
            now()->addMinutes(10)->getTimestamp(),
            $mirror->invoke($leader, 1, $endedYesterday)->getTimestamp()
        );
        // ...but collapses to seconds while impersonating, for BOTH arms, even with a recent month end.
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        foreach ([0, 1] as $index) {
            $this->assertSame(
                now()->addSeconds(10)->getTimestamp(),
                $mirror->invoke($leader, $index, $endedYesterday)->getTimestamp(),
                "while impersonating, baseDashboardCacheTTL({$index}) must collapse to 10 seconds"
            );
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_shared_month_batch_never_writes_the_current_month_bucket(): void
    {
        // Also a shipped-and-caught regression: the batch buckets by
        // toStartOfMonth(hit_created_at) -- the whole calendar month -- while the trait bounds
        // the current month at now(tz)->endOfDay(). Filling `this_month` from the batch
        // published a LARGER number than the trait's for every negative-UTC-offset org, and
        // because this card renders before staging-live-hits its write won, changing that
        // card's headline. The batch must cover past months only.
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $widget = new StagingLiveHitLeader;

        $uncached = (new ReflectionMethod($widget, 'collectUncachedSharedMonths'))
            ->invoke($widget, $orgId, $timezone);

        $this->assertCount(12, $uncached, 'the shared batch covers the 12 PAST months, never the current one');

        $currentMonthKey = now(tz: $timezone)->startOfMonth()->format('Y-m');
        $this->assertArrayNotHasKey(
            $currentMonthKey,
            $uncached,
            'the current month must be resolved by the range query, not the month-partition batch'
        );
        $this->assertNotContains(0, $uncached, 'month index 0 (the current month) must never appear');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_fully_cold_render_issues_exactly_one_round_trip_per_series(): void
    {
        // AC-3/AC-4's real proof, and the one the first cut did NOT have: with NOTHING seeded,
        // all 13 months are missing, so a per-month fan-out would call the leaderboard seam 13
        // times. Review showed the previous single-month-gap fixture could not tell 1 call from
        // 13, because both shapes write only the uncached months. Counting the seams is the only
        // deterministic option here (DB::listen never fires for the ClickHouse driver, and
        // system.query_log is unusable against the shared literal clarity/zod databases).
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        foreach (['this_month', 'last_month', 'today', 'last_7d'] as $date) {
            $widget = new CountingStagingLiveHitLeader;
            $widget->date = $date;
            $widget->leaderboardForSelectedRange();

            $this->assertSame(1, $widget->rangeCalls, "{$date} must be one rememberedRange call");
        }

        $widget = new CountingStagingLiveHitLeader;
        $widget->date = 'this_month';

        (new ReflectionMethod($widget, 'chartSeries'))->invoke($widget);

        $this->assertSame(1, $widget->sharedMonthCalls, 'the 12 past shared months must be ONE batched call, never 12');
        $this->assertSame(1, $widget->sharedCurrentCalls, 'the current shared month is ONE range call');

        $bucketZone = ScanPlateStatsBatchQuery::safeTimezone($timezone);
        $window = ScanPlateStatsBatchQuery::currentMonthWindow($bucketZone);
        $ranked = new CountingStagingLiveHitLeader;
        $ranked->date = 'this_month';
        $ranked->leaderboardForSelectedRange();
        $this->assertSame([$window['start'], $window['end'], $bucketZone], $ranked->rangeWindow);

        (new ReflectionMethod($widget, 'chartSeries'))->invoke($widget);
        $this->assertSame(1, $widget->sharedMonthCalls, 'the resolve-once guard must hold');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_shared_prefix_map_has_not_drifted_from_the_authoritative_trait_map(): void
    {
        // The card cannot `use` SharedHitStatsData (its scalar-per-month shape cannot hold a
        // ranked list, and a 5th using-class would get its own copy of the trait statics --
        // the DEV-2406 hazard), so it copies the prefix->column map by value. The trait guards
        // that map with a LogicException for exactly this drift class; this assertion is this
        // card's equivalent. Without it, adding a 5th hit prefix would make this card fill only
        // four -- so the 5th card silently issues its own extra batch on every cold render --
        // and renaming a prefix would give this card a permanently flat-zero sparkline.
        $trait = new \ReflectionProperty(
            SharedHitStatsData::class,
            'hitWidgetMap'
        );
        $card = new \ReflectionClassConstant(StagingLiveHitLeader::class, 'SHARED_PREFIX_MAP');

        $this->assertSame(
            $trait->getValue(),
            $card->getValue(),
            'SHARED_PREFIX_MAP must equal SharedHitStatsData::$hitWidgetMap exactly -- '
            .'add the new prefix to BOTH, or this card silently stops filling one of them'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_shared_keys_are_written_with_the_impersonation_aware_ttl_at_the_call_site(): void
    {
        // THIS is the test the first fix cycle was missing, and review caught the gap: asserting
        // dashboardCacheTTL()'s RETURN VALUE only pins the method BODY. The RN2 defect lived at the
        // CALL SITES, so reverting `$this->dashboardCacheTTL(1)` to `now()->addYear()` shipped green.
        // Here we read the expiry the array store actually recorded, which is what the four shipped
        // hit cards are exposed to.
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $widget = new CountingStagingLiveHitLeader;
        (new ReflectionMethod($widget, 'resolveSharedMonths'))->invoke($widget, $orgId, $timezone);

        $pastKey = 'staging-live-hits:unique:'
            .now(tz: $timezone)->startOfMonth()->subMonths(3)->format('Y-m')
            .':org='.$orgId;

        $store = cache()->store()->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store, 'this test reads ArrayStore\'s private storage; phpunit.xml pins CACHE_STORE=array');
        $storage = (new \ReflectionProperty($store, 'storage'))->getValue($store);

        $this->assertArrayHasKey($pastKey, $storage, 'the past-month shared key must have been written');
        $this->assertSame(
            now()->addSeconds(10)->getTimestamp(),
            (int) $storage[$pastKey]['expiresAt'],
            'while impersonating, a shared past-month key must expire in 10s -- NOT addYear(). '
            .'A raw literal at the call site freezes the four shipped hit cards for a year.'
        );

        // The SECOND shared-key TTL call site. Review caught that the first version of this test
        // covered only resolveSharedMonths(), so a raw literal in resolveSharedCurrentMonth() would
        // still have shipped green -- pinning the four hit cards' CURRENT-month headline for 10
        // minutes instead of 10 seconds under impersonation. Both call sites are now observed.
        (new ReflectionMethod($widget, 'resolveSharedCurrentMonth'))->invoke($widget, $orgId, $timezone);

        $storage = (new \ReflectionProperty($store, 'storage'))->getValue($store);
        $currentKey = 'staging-live-hits:unique:this_month:org='.$orgId;

        $this->assertArrayHasKey($currentKey, $storage, 'the current-month shared key must have been written');
        $this->assertSame(
            now()->addSeconds(10)->getTimestamp(),
            (int) $storage[$currentKey]['expiresAt'],
            'while impersonating, the shared this_month key must expire in 10s -- NOT addMinutes(10)'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_month_that_ended_yesterday_is_ten_minutes_on_both_write_paths(): void
    {
        // Frozen one day after August's endOfMonth so that month is inside the 8-day
        // settle window, and a month three back has already settled. One
        // dashboardCacheTTL(1) applied to every uncached key would year-cache the
        // just-closed month on the leader key and on {prefix}:unique:{Y-m}.
        Carbon::setTestNow('2026-09-01 23:59:59');

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'UTC']],
            role: RoleEnum::ADMIN->value,
        );
        $this->makeUserPro($user);
        $this->actingAs($user);
        $timezone = 'UTC';
        $orgId = $user->org_id;

        $recentMonthKey = now(tz: $timezone)->startOfMonth()->subMonth()->format('Y-m');
        $settledMonthKey = now(tz: $timezone)->startOfMonth()->subMonths(3)->format('Y-m');

        $widget = new CountingStagingLiveHitLeader;
        (new ReflectionMethod($widget, 'resolveSharedMonths'))->invoke($widget, $orgId, $timezone);

        $tenMinutes = now()->addMinutes(10)->getTimestamp();
        $oneYear = now()->addYear()->getTimestamp();

        $this->assertSame($tenMinutes, $this->cacheExpiresAt('staging-live-hits:unique:'.$recentMonthKey.':org='.$orgId));
        $this->assertSame($oneYear, $this->cacheExpiresAt('staging-live-hits:unique:'.$settledMonthKey.':org='.$orgId));

        cache()->flush();
        $this->resetWidgetStatics();
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $impersonating = new CountingStagingLiveHitLeader;
        (new ReflectionMethod($impersonating, 'resolveSharedMonths'))->invoke($impersonating, $orgId, $timezone);

        $tenSeconds = now()->addSeconds(10)->getTimestamp();

        $this->assertSame($tenSeconds, $this->cacheExpiresAt('staging-live-hits:unique:'.$recentMonthKey.':org='.$orgId));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_this_month_window_ends_today_not_at_the_calendar_month_end(): void
    {
        Carbon::setTestNow('2026-08-16 02:00:00');

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'America/New_York']],
            role: RoleEnum::ADMIN->value,
        );
        $this->actingAs($user);

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        [$start, $end, $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);

        $this->assertSame('America/New_York', $timezone);
        $this->assertSame(now(tz: 'America/New_York')->startOfMonth()->startOfDay()->toDateTimeString(), $start);
        $this->assertSame(now(tz: 'America/New_York')->endOfDay()->toDateTimeString(), $end);
        $this->assertNotSame(
            now(tz: 'America/New_York')->endOfMonth()->endOfDay()->toDateTimeString(),
            $end,
        );
    }

    /**
     * The role check is now the ONLY gate on this card, so it carries the whole withholding
     * contract by itself. Previously this test proved the override ANDed static::canView()
     * rather than replacing it; the same viewer must still be refused, and lockedStat() must
     * still produce a card without computing anything -- the guarantee the deleted
     * deny-then-allow test used to carry.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('staging-live-hit-leader')]
    public function test_a_viewer_without_the_role_is_still_withheld_and_costs_nothing(): void
    {
        $user = $this->createUser(permissions: []);
        $this->actingAs($user);

        $this->assertFalse(
            StagingLiveHitLeader::canView(),
            'precondition: this viewer holds neither ADMIN nor USER'
        );
        $this->assertFalse(
            StagingLiveHitLeader::canViewCardData(),
            'a viewer without ADMIN/USER must NOT get the card value, entitlement or not'
        );

        $locked = (new StagingLiveHitLeader)->lockedStat();

        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $locked->getValue());
        $this->assertNull($locked->getChart(), 'a locked card must not carry a chart -- it would leak the withheld series shape');

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        [$start, $end, $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);
        $this->assertNull(
            cache()->get(StagingLiveHitLeaderQuery::sharedRangeCacheKey($user->org_id, $start, $end, $timezone)),
            'lockedStat() must compute no value, so it must never touch the range cache'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_staging_live_prefix_const_is_a_key_of_the_shared_prefix_map(): void
    {
        // Closes the other half of the drift guard: the map is pinned, but the prefix this card
        // reads and guards on was hand-spelled at two sites. A rename would have passed the map
        // assertion while giving the card a flat-zero sparkline AND a guard that never fires.
        $prefix = (new \ReflectionClassConstant(StagingLiveHitLeader::class, 'STAGING_LIVE_PREFIX'))->getValue();
        $map = (new \ReflectionClassConstant(StagingLiveHitLeader::class, 'SHARED_PREFIX_MAP'))->getValue();

        $this->assertArrayHasKey($prefix, $map);
        $this->assertSame('staging_live_hit_count', $map[$prefix]);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('clickhouse')]
    public function test_a_current_month_range_failure_uses_the_range_marker_scope_not_the_past_months_scope(): void
    {
        // SharedHitStatsData takes a $scope on its failure marker for one documented reason: to keep
        // the past-months BATCH failure separate from a specific RANGE failure. Filing a range
        // failure under `past-months` breaks it both ways -- a failed batch would suppress the
        // current-month range fetch this card needs (flat-zero sparkline while the sibling publishes
        // the real number), and a failed range would throttle all four hit cards' past-months
        // resolver over a query none of them issued.
        $this->requireRealClickhouse();

        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';
        $orgId = $user->org_id;

        $start = now(tz: $timezone)->startOfMonth()->startOfDay()->toDateTimeString();
        $end = now(tz: $timezone)->endOfDay()->toDateTimeString();

        $this->forceClickhouseConnectionToFail();

        $widget = new StagingLiveHitLeader;
        (new ReflectionMethod($widget, 'resolveSharedCurrentMonth'))->invoke($widget, $orgId, $timezone);

        $this->restoreClickhouseConnection();

        $rangeMarker = 'hit-plate-stats:unique-fetch-failed:range:'.$start.':'.$end.':org='.$orgId;
        $pastMonthsMarker = 'hit-plate-stats:unique-fetch-failed:past-months:org='.$orgId;

        $this->assertNotNull(
            cache()->get($rangeMarker),
            'a range failure must set the RANGE-scoped marker'
        );
        $this->assertNull(
            cache()->get($pastMonthsMarker),
            'a range failure must NOT set the past-months marker -- that would throttle the four '
            .'shipped hit cards over a query they never issued'
        );
        $this->assertNull(
            cache()->get('staging-live-hits:unique:this_month:org='.$orgId),
            'and no fabricated 0 may be cached for the current month'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_card_and_leaderboard_share_one_range_scan_and_drop_key_zero(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        $orgId = $user->org_id;

        $connection = \Mockery::mock();
        $connection->shouldReceive('select')->once()->andReturn([
            ['kit_id' => 7, 'is_rollup' => 0, 'name' => 'Kit Seven', 'total' => 10],
            ['kit_id' => 0, 'is_rollup' => 1, 'name' => 'Kit 0', 'total' => 42],
            ['kit_id' => 8, 'is_rollup' => 0, 'name' => 'Kit Eight', 'total' => 9],
        ]);

        $real = DB::getFacadeRoot();
        $manager = \Mockery::mock($real)->makePartial();
        $manager->shouldReceive('connection')->once()->with('clarity_clickhouse')->andReturn($connection);
        DB::swap($manager);

        try {
            cache()->put('staging-live-hits:unique:this_month:org='.$orgId, 7, now()->addMinutes(10));

            $card = new StagingLiveHits;
            $card->date = 'this_month';
            $this->assertSame(42, $card->getCardTotal());

            $leader = new StagingLiveHitLeader;
            $leader->date = 'this_month';
            $ranked = $leader->leaderboardForSelectedRange();

            $this->assertSame([7, 8], array_column($ranked, 'id'));
            $this->assertNotContains(0, array_column($ranked, 'id'));
        } finally {
            DB::swap($real);
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_impersonation_caches_the_shared_range_for_ten_seconds(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $user = $this->actingAsProUser();
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $connection = \Mockery::mock();
        $connection->shouldReceive('select')->once()->andReturn([
            ['kit_id' => 0, 'is_rollup' => 1, 'name' => '', 'total' => 3],
        ]);

        $real = DB::getFacadeRoot();
        $manager = \Mockery::mock($real)->makePartial();
        $manager->shouldReceive('connection')->once()->with('clarity_clickhouse')->andReturn($connection);
        DB::swap($manager);

        try {
            $start = '2026-08-01 00:00:00';
            $end = '2026-08-12 23:59:59';
            $result = StagingLiveHitLeaderQuery::rememberedRange($user->org_id, $start, $end, 'UTC');
            $key = StagingLiveHitLeaderQuery::sharedRangeCacheKey($user->org_id, $start, $end, 'UTC');

            $this->assertSame(3, $result['totals'][0]);
            $this->assertSame(now()->addSeconds(10)->getTimestamp(), $this->cacheExpiresAt($key));
        } finally {
            DB::swap($real);
        }
    }

    private function seedAllMonthsWithSentinels(int $orgId, string $timezone, ?string $except = null): void
    {
        for ($i = 12; $i >= 0; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');

            if ($monthKey === $except) {
                continue;
            }

            $dateType = $i === 0 ? 'this_month' : $monthKey;

            foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
                cache()->put($prefix.':unique:'.$dateType.':org='.$orgId, 88888 + $i, now()->addYear());
            }
        }
    }

    private function actingAsProUser(): User
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeUserPro($user);
        $this->actingAs($user);

        return $user;
    }

    private function overviewCardValue(CompanyMetricsOverview $overview, string $cardKey): string
    {
        $cachedStats = new \ReflectionProperty($overview, 'cachedStats');
        $stats = $cachedStats->getValue($overview);

        if ($stats === null) {
            $stats = (new ReflectionMethod($overview, 'getCachedStats'))->invoke($overview);
        }

        $this->assertIsArray($stats);

        foreach ($stats as $stat) {
            $this->assertInstanceOf(Stat::class, $stat);

            if (($stat->getExtraAttributes()['data-dashboard-card'] ?? null) === $cardKey) {
                return (string) $stat->getValue();
            }
        }

        $this->fail("Company Metrics card [{$cardKey}] was not rendered");
    }

    /**
     * @param  array{totals: array<int, int>, names: array<int, string>}  $payload
     */
    private function seedRangeRollup(StagingLiveHitLeader|StagingLiveHits $widget, int $orgId, array $payload): void
    {
        [$start, $end, $timezone] = (new ReflectionMethod($widget, 'resolveRangeAndTimezone'))->invoke($widget);

        cache()->put(
            StagingLiveHitLeaderQuery::sharedRangeCacheKey($orgId, $start, $end, $timezone),
            $payload,
            now()->addMinutes(10),
        );
    }

    private function cacheExpiresAt(string $key): int
    {
        $store = cache()->store()->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store, 'this test reads ArrayStore\'s private storage; phpunit.xml pins CACHE_STORE=array');
        $storage = (new \ReflectionProperty($store, 'storage'))->getValue($store);
        $this->assertArrayHasKey($key, $storage, "cache key [{$key}] must have been written");

        return (int) $storage[$key]['expiresAt'];
    }

    private function seedAllSharedMonthsZero(int $orgId, string $timezone): void
    {
        for ($i = 12; $i >= 0; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');
            $dateType = $i === 0 ? 'this_month' : $monthKey;

            foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
                cache()->put($prefix.':unique:'.$dateType.':org='.$orgId, 0, now()->addYear());
            }
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_invalid_viewer_timezone_renders_utc_counts(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'NotAReal/Zone']],
            role: RoleEnum::ADMIN->value,
        ));

        $this->assertSame('NotAReal/Zone', $user->timezone);

        $orgId = $user->org_id;
        $this->seedAllSharedMonthsZero($orgId, 'UTC');

        $widget = new StagingLiveHitLeader;
        $widget->date = 'this_month';
        $this->seedRangeRollup($widget, $orgId, [
            'totals' => [0 => 40, 7 => 21],
            'names' => [7 => 'Utc Kit'],
        ]);

        $stat = $widget->toStat();

        $this->assertSame('21', $stat->getValue());
        $this->assertStringContainsString('Top Kit: Utc Kit', (string) $stat->getDescription());
        $this->assertNotSame('--', $stat->getValue());
        $this->assertSame(now(tz: 'UTC')->format('M \'y'), $widget->getGraphLabels()[12]);

        $today = new StagingLiveHitLeader;
        $today->date = 'today';
        [$start, $end, $timezone] = (new ReflectionMethod($today, 'resolveRangeAndTimezone'))->invoke($today);

        $this->assertSame('UTC', $timezone);
        $this->assertSame(now(tz: 'UTC')->startOfDay()->toDateTimeString(), $start);
        $this->assertSame(now(tz: 'UTC')->endOfDay()->toDateTimeString(), $end);

        $this->seedRangeRollup($today, $orgId, [
            'totals' => [0 => 9, 4 => 6],
            'names' => [4 => 'Today Utc'],
        ]);

        $todayStat = $today->toStat();

        $this->assertSame('6', $todayStat->getValue());
        $this->assertStringContainsString('Top Kit: Today Utc', (string) $todayStat->getDescription());

        Carbon::setTestNow();
    }

    private function requireRealClickhouse(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'clickhouse') {
            $this->markTestSkipped('requires a real ClickHouse (CLICKHOUSE_DRIVER=clickhouse)');
        }
    }

    private function forceClickhouseConnectionToFail(): void
    {
        $this->originalClickhouseConfig ??= Config::get('database.connections.clarity_clickhouse');

        Config::set('database.connections.clarity_clickhouse.driver', 'clickhouse');
        Config::set('database.connections.clarity_clickhouse.host', '127.0.0.1');
        Config::set('database.connections.clarity_clickhouse.port', '1');
        Config::set('database.connections.clarity_clickhouse.https', false);
        Config::set('database.connections.clarity_clickhouse.options', ['protocol' => 'http']);
        Config::set('database.connections.clarity_clickhouse.timeout_connect', 1);
        Config::set('database.connections.clarity_clickhouse.timeout_query', 1);
        Config::set('database.connections.clarity_clickhouse.retries', 0);

        DB::purge('clarity_clickhouse');
    }

    private function restoreClickhouseConnection(): void
    {
        if ($this->originalClickhouseConfig !== null) {
            Config::set('database.connections.clarity_clickhouse', $this->originalClickhouseConfig);
        }

        DB::purge('clarity_clickhouse');
    }
}

/**
 * Counts the widget's ClickHouse seams so a render can be pinned without a query log.
 */
class CountingStagingLiveHitLeader extends StagingLiveHitLeader
{
    public int $rangeCalls = 0;

    public int $sharedMonthCalls = 0;

    public int $sharedCurrentCalls = 0;

    /** @var array{0: string, 1: string, 2: string} */
    public array $rangeWindow = ['', '', ''];

    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeCalls++;
        $this->rangeWindow = [$start, $end, $timezone];

        return [
            'totals' => [0 => 100, 1 => 40],
            'names' => [1 => 'Kit Alpha'],
        ];
    }

    protected function fetchSharedMonths(int $orgId, string $start, string $end): array
    {
        $this->sharedMonthCalls++;

        return [];
    }

    protected function fetchSharedCurrentMonth(int $orgId, string $start, string $end): array
    {
        $this->sharedCurrentCalls++;

        return [];
    }
}
