<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\LprRepossessionsQuery;
use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use App\Filament\Exports\LprRepossessionExporter;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\LprRepossessions;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Exports\Models\Export;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DEV-2464 t3. Modelled on UniqueLprCaseClaimsTest + UniqueLprCaseClaimsHerdGuardTest
 * (DEV-2463), combined into one file because this node's target_files allow only this
 * test file. Uses counting/stubbing/throwing subclasses -- never DB::listen(), which the
 * ClickHouse driver never fires.
 */
class LprRepossessionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_get_card_key(): void
    {
        $this->assertSame('lpr-repossessions', (new LprRepossessions)->getCardKey());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_stat_color_is_explicit_and_not_gray(): void
    {
        $color = (new LprRepossessions)->getStatColor();

        $this->assertNotSame('gray', $color, 'default => gray would render identically to the locked state');
        $this->assertNotSame('', $color);

        $this->assertNotNull(
            FilamentColor::getColor($color),
            "getStatColor() returned '{$color}', which is not registered in PlatformPanelProvider::colors() -- it renders untinted",
        );
    }

    /**
     * The Pro gate is GONE. This card used to deny a guest, a BASIC org and a PRO_LITE org,
     * and to open for CLARITY_PRO / CLARITY_PRO_PLUS or an admin_impersonator session; six
     * tests asserted that. `FeatureEnum::COMPANY_METRICS` no longer exists, so all of it
     * collapses to one property: entitlement is irrelevant and only the ADMIN/USER role
     * decides.
     *
     * Kept as an assertion rather than deleted, and pointed the other way on purpose -- this
     * is what fails if a Pro gate is ever silently re-added to this card.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_can_view_card_data_needs_no_entitlement(): void
    {
        // Guest: the default short-circuits to true (the call must be TOTAL, not merely
        // permissive -- see DashboardCardVisibilityTest).
        $this->assertTrue(auth()->guest());
        $this->assertTrue(LprRepossessions::canViewCardData());

        // A plain ADMIN on a BASIC org -- the case that used to be denied.
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);
        $this->assertTrue(LprRepossessions::canViewCardData());

        // PRO_LITE, the other formerly-denied tier.
        $user->org->meta()->create([
            'properties' => [
                'platform_repo_pro' => true,
                'platform_repo_pro_tier' => ProTierEnum::PRO_LITE->value,
            ],
        ]);
        $user->org->refresh();
        cache()->forget('org_pro_enabled_'.$user->org_id);
        Feature::flushCache();

        $this->assertTrue(LprRepossessions::canViewCardData());

        // And an impersonator session changes nothing, because there is nothing to bypass.
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);
        $this->assertTrue(LprRepossessions::canViewCardData());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_locked_stat_renders_the_placeholder_and_executes_no_query(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new CountingLprRepossessions;
        $stat = $widget->lockedStat();

        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $stat->getValue());
        $this->assertNull($stat->getChart());
        $this->assertSame(0, $widget->monthlySeriesCalls, 'lockedStat() must never touch the monthly series');
        $this->assertSame(0, $widget->windowCalls, 'lockedStat() must never touch the window query');
    }

    /**
     * AC's real proof: EXACTLY one round trip per seam, for all four filter states.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_exactly_one_round_trip_per_seam_across_all_four_filter_states(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        foreach ([
            'today' => true,
            'last_7d' => true,
            'this_month' => false,
            'last_month' => false,
        ] as $date => $expectsWindowCall) {
            cache()->flush();

            $widget = new CountingLprRepossessions;
            $widget->date = $date;

            $widget->toStat();

            $this->assertSame(1, $widget->monthlySeriesCalls, "{$date}: the 13-month series must be ONE batched call, never a fan-out");
            $this->assertSame(
                $expectsWindowCall ? 1 : 0,
                $widget->windowCalls,
                "{$date}: the single-window query must fire only for today/last_7d"
            );

            $widget->toStat();
            $this->assertSame(1, $widget->monthlySeriesCalls, "{$date}: the per-instance memo must hold");
            $this->assertSame($expectsWindowCall ? 1 : 0, $widget->windowCalls, "{$date}: the per-instance memo must hold");

            $freshWidget = new CountingLprRepossessions;
            $freshWidget->date = $date;
            $freshWidget->toStat();

            $this->assertSame(0, $freshWidget->monthlySeriesCalls, "{$date}: a fresh instance must read the already-warm cache");
            $this->assertSame(0, $freshWidget->windowCalls, "{$date}: a fresh instance must read the already-warm cache");

            $widget->expanded = true;
            $this->assertTrue($widget->exportAction()->isVisible());
            $widget->expanded = false;
            $this->assertFalse($widget->exportAction()->isVisible());
            $this->assertSame(1, $widget->monthlySeriesCalls, "{$date}: showing the export action must not run another series query");
            $this->assertSame($expectsWindowCall ? 1 : 0, $widget->windowCalls, "{$date}: showing the export action must not run the window query");
        }
    }

    /**
     * The load-bearing risk: this card's monthly buckets are UTC-anchored, NOT the
     * viewer's timezone.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_month_buckets_align_on_utc_not_the_viewers_timezone(): void
    {
        Carbon::setTestNow('2026-08-01 02:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->assertSame('America/Chicago', $user->timezone);
        $this->actingAs($user);

        // The discriminator, asserted rather than assumed (review round 14, lanes 2 T1 and 3):
        // this test's whole power rests on the frozen instant straddling the two calendars. It
        // does so only while APP_TIMEZONE is UTC; set it to America/Chicago and 02:00 becomes
        // 07:00 UTC, both calendars land in August, and the card's central UTC-vs-viewer
        // divergence would go unpinned with the test still green.
        $this->assertSame('2026-08', now('UTC')->format('Y-m'), 'precondition: UTC is already August');
        $this->assertSame('2026-07', now(tz: $user->timezone)->format('Y-m'), 'precondition: the viewer is still in July');

        $stub = new StubbingLprRepossessions;
        $stub->series = [
            '2026-08-01' => ['deduped' => 999, 'db_repos' => 777],
        ];

        $getMonthlyData = new ReflectionMethod($stub, 'getMonthlyData');
        $getMonthlyData->setAccessible(true);
        $data = $getMonthlyData->invoke($stub, false);

        $this->assertCount(13, $data, 'the series must always be 13 elements, even seeded with one bucket');
        $this->assertSame(
            777,
            $data[12],
            'the CURRENT month bucket must be the UTC month (August), not the Chicago-local month (July)'
        );
        $this->assertSame(0, $data[11], 'the prior index must be 0 -- no bucket was seeded for it');
    }

    /**
     * The LABEL axis must co-vary with the UTC data axis, and nothing pinned it.
     *
     * Review round 13 flagged this unanimously (lane 1 M3, lane 2 M4) and the sibling needed a
     * whole dedicated file to close the identical miss -- UniqueLprCaseClaimsLabelAxisTest's
     * docblock records that "deleting getGraphLabels(), getHighlightedMonthsFromFilter() and
     * getDescription() left every test green and fully restored the defect (all 13 bars labelled
     * one month behind their data for ~5-6h at every UTC month start)". DashboardMonthLabelsTest
     * exists to guard this class of bug but does not iterate this card.
     *
     * The frozen instant is the discriminator: 02:00 UTC on the 1st is still 21:00 on the
     * PREVIOUS day in Chicago, so the base implementations (which read
     * auth()->user()->timezone) produce "Jul '26" while the card's UTC overrides produce
     * "Aug '26". Deleting any of the three overrides fails this test.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_label_axis_is_utc_and_co_varies_with_the_data_axis(): void
    {
        Carbon::setTestNow('2026-08-01 02:00:00');

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'America/Chicago']],
            role: RoleEnum::ADMIN->value
        );
        $this->actingAs($user);
        $this->assertSame('2026-07-31', now(tz: $user->timezone)->format('Y-m-d'), 'precondition: the viewer is still in July');

        $widget = new StubbingLprRepossessions;
        $widget->series = ['2026-08-01' => ['deduped' => 5, 'db_repos' => 3]];

        $labels = $widget->getGraphLabels();

        $this->assertCount(13, $labels);
        $this->assertSame(
            "Aug '26",
            $labels[12],
            'the FINAL label must name the UTC month the final bucket holds -- a viewer-local axis '
            .'labels every one of the 13 bars a month behind its data'
        );
        $this->assertSame("Jul '26", $labels[11]);

        $highlighted = new ReflectionMethod($widget, 'getHighlightedMonthsFromFilter');
        $highlighted->setAccessible(true);
        $this->assertSame(
            ["Aug '26"],
            $highlighted->invoke($widget),
            'the highlighted month must be the UTC current month, or the wrong bar is tinted'
        );

        $widget->date = 'this_month';
        $this->assertStringContainsString(
            "Aug '26",
            (string) $widget->getDescription(),
            'getDescription() must name the UTC months it is reporting'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_months_with_no_data_render_zero_not_missing(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $stub = new StubbingLprRepossessions;
        $stub->series = [];

        $method = new ReflectionMethod($stub, 'getMonthlyData');
        $method->setAccessible(true);
        $data = $method->invoke($stub, false);

        $this->assertCount(13, $data);
        $this->assertSame(array_fill(0, 13, 0), $data);
    }

    /**
     * Reads the 'db_repos' funnel stage, not 'deduped' -- the two are different numbers
     * (this card counts only the subset of billed claims that also recovered), and a
     * stage-swap mutant would still pass every other test here.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_monthly_series_reads_the_db_repos_stage_not_deduped(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $stub = new StubbingLprRepossessions;
        $stub->series = [
            '2026-08-01' => ['deduped' => 500, 'db_repos' => 42],
        ];

        $method = new ReflectionMethod($stub, 'getMonthlyData');
        $method->setAccessible(true);
        $data = $method->invoke($stub, false);

        $this->assertSame(42, $data[12], 'getMonthlyData() must read db_repos, not deduped');
    }

    /**
     * Same stage-selection guarantee on the window seam: fetchWindowTotal() must pick
     * 'db_repos' out of runWindowQuery()'s array, not 'deduped'.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_window_total_reads_the_db_repos_stage_not_deduped(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new StageSelectingLprRepossessions;

        $method = new ReflectionMethod($widget, 'fetchWindowTotal');
        $method->setAccessible(true);
        $total = $method->invoke($widget, 1, Carbon::now(), Carbon::now());

        $this->assertSame(222, $total, 'fetchWindowTotal() must select db_repos (222), not deduped (111)');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_a_clickhouse_failure_does_not_render_as_zero(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new ThrowingLprRepossessions;
        $widget->date = 'today';

        $this->expectException(\RuntimeException::class);

        $widget->toStat();
    }

    /**
     * The warm path must take NO lock -- the guard ordering that matters (see
     * HeldFlexibleCache's docblock).
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_a_warm_cache_is_served_without_taking_the_cold_lock(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new LprCountingHerdWidget;
        $widget->date = 'this_month';
        $this->invokeMonthly($widget);
        $this->assertSame(1, $widget->calls, 'the first resolve must compute');

        // The key production used, not one rebuilt here: this test takes the cold lock to prove
        // the warm path does NOT block on it, so a rebuilt key that drifted would lock something
        // irrelevant and the test would still PASS -- the unsafe direction.
        $key = $widget->currentKey;
        $lock = Cache::lock($key.':cold', 90);
        $this->assertTrue($lock->get(), 'precondition: the cold lock is free');

        try {
            $fresh = new LprCountingHerdWidget;
            $fresh->date = 'this_month';
            $started = microtime(true);
            $value = $this->invokeMonthly($fresh);
            $elapsed = microtime(true) - $started;

            $this->assertSame(0, $fresh->calls, 'a warm read must not compute');
            $this->assertCount(13, $value);
            $this->assertLessThan(
                2.0,
                $elapsed,
                'a warm read must not block on the cold lock (it should never take it)'
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * On a total miss the guard must serialise: the waiter reads what the holder wrote
     * rather than issuing its own ~584M-row scan.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_a_waiter_reads_the_holders_value_instead_of_recomputing(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $holder = new LprCountingHerdWidget;
        $holder->date = 'this_month';
        $this->invokeMonthly($holder);
        $this->assertSame(1, $holder->calls);

        $waiter = new LprCountingHerdWidget;
        $waiter->date = 'this_month';
        $this->invokeMonthly($waiter);

        $this->assertSame(0, $waiter->calls, 'the waiter must read the cache, not re-scan');
    }

    /**
     * Review round 11 (lane 3, M9): the sizing test below reads the CONSTANTS by reflection, so a
     * one-token slip in one of the three near-identical accessors -- coldLockSeconds() returning
     * COLD_BLOCK_SECONDS, say -- is invisible to it. Only refreshLockSeconds() was proven to reach
     * its consumer. This pins all three through the accessors the trait actually calls, and pins
     * the ordering invariant that the constants exist to satisfy.
     *
     * Round 12 (all three lanes) then found that deriving every window from one base had made two
     * of those accessors return the SAME number, so a swap between them stayed undetectable even
     * with this test present. Hence the pairwise-distinctness assertion: it is what gives the
     * three assertions above their teeth.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_guard_accessors_reach_their_constants_and_preserve_the_ordering_invariant(): void
    {
        $widget = new LprRepossessions;
        $accessor = static function (string $name) use ($widget): int {
            $m = new ReflectionMethod(LprRepossessions::class, $name);
            $m->setAccessible(true);

            return $m->invoke($widget);
        };

        $r = new \ReflectionClass(LprRepossessions::class);
        $timeout = $r->getConstant('QUERY_TIMEOUT_SECONDS');

        $this->assertSame(
            $r->getConstant('COLD_BLOCK_SECONDS'),
            $accessor('coldBlockSeconds'),
            'coldBlockSeconds() must return COLD_BLOCK_SECONDS'
        );
        $this->assertSame(
            $r->getConstant('COLD_LOCK_SECONDS'),
            $accessor('coldLockSeconds'),
            'coldLockSeconds() must return COLD_LOCK_SECONDS, not one of its two near-identical siblings'
        );
        $this->assertSame(
            $r->getConstant('REFRESH_LOCK_SECONDS'),
            $accessor('refreshLockSeconds'),
            'refreshLockSeconds() must return REFRESH_LOCK_SECONDS'
        );

        $this->assertGreaterThan(
            $accessor('coldBlockSeconds'),
            $accessor('coldLockSeconds'),
            'the lock TTL must outlast the block window or a holder loses the mutex while waiters '
            .'are still blocked on it'
        );
        $this->assertGreaterThan(
            $timeout,
            $accessor('coldLockSeconds'),
            'the lock TTL must also outlast a holder that runs all the way to the client timeout'
        );

        $values = [
            'coldBlockSeconds' => $accessor('coldBlockSeconds'),
            'coldLockSeconds' => $accessor('coldLockSeconds'),
            'refreshLockSeconds' => $accessor('refreshLockSeconds'),
        ];

        $this->assertSame(
            \count($values),
            \count(\array_unique($values)),
            'the three guard accessors must return PAIRWISE DISTINCT values ('.\json_encode($values).'). '
            .'They are near-identical one-liners, so two equal values make a swap between them '
            .'undetectable by every assertion above -- which is exactly how round 12 found two '
            .'surviving accessor mutants'
        );
    }

    /**
     * The windows are derived from two FIXED production ceilings, so this asserts against those
     * rather than against a measured latency: the client query timeout, and the request budget
     * kamal-proxy allows (config/deploy.yml sets no proxy.response_timeout, so its 30s default
     * applies).
     *
     * Round 11 found the block window sat below the query timeout and it was raised above it;
     * round 12 found that raising it made a two-leg cold render block for ~150s against a 30s
     * request budget. The invariant that actually matters is therefore the TOTAL across legs,
     * which is what this pins.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_guard_windows_fit_the_proxy_request_budget_and_outlast_the_query_timeout(): void
    {
        $r = new \ReflectionClass(LprRepossessions::class);

        $block = $r->getConstant('COLD_BLOCK_SECONDS');
        $budget = $r->getConstant('COLD_BLOCK_BUDGET_SECONDS');
        $legs = $r->getConstant('GUARDED_COLD_LEGS');
        $lockTtl = $r->getConstant('COLD_LOCK_SECONDS');
        $refresh = $r->getConstant('REFRESH_LOCK_SECONDS');
        $timeout = (int) config('database.connections.clarity_clickhouse.timeout_query', 60);

        $this->assertIsInt($block, 'COLD_BLOCK_SECONDS divides the budget by the leg count, so the '
            .'budget must divide evenly -- a float here would be handed straight to Lock::block()');
        $this->assertSame(
            $budget,
            $block * $legs,
            'the per-leg window times the leg count must equal the whole budget: the two guarded '
            .'legs can be cold in the SAME render, so their block windows are additive'
        );
        $this->assertLessThan(
            30,
            $budget,
            'the TOTAL time one request may block across all guarded legs must stay inside '
            ."kamal-proxy's 30s default response timeout -- past that the client is gone and a "
            .'blocking waiter is holding a worker for nobody'
        );
        $this->assertGreaterThan(
            $timeout,
            $lockTtl,
            'COLD_LOCK_SECONDS must exceed the query timeout or a slow holder loses the mutex mid-query'
        );
        $this->assertGreaterThan(
            LprRepossessions::OBSERVED_MAX_LATENCY_SECONDS,
            $lockTtl,
            'COLD_LOCK_SECONDS must also outlast the worst single run actually observed, not just '
            .'the median'
        );
        $this->assertGreaterThan(
            $timeout,
            $refresh,
            "Cache::flexible's refresh lock defaults to NO expiry, so a worker killed mid-refresh "
            .'would wedge the key until the hard TTL; it must outlast the query it guards'
        );
    }

    /**
     * Proves the guard EXISTS on the window leg, not merely that it is well-ordered.
     *
     * Review round 11 (lanes 2 and 3): the window leg had NO herd guard. today/last_7d resolved
     * through the base currentPeriodTotal(), a bare cache()->remember() -- no lock, no stale-serve
     * -- while running the same ~584M-row query as the 13-month series. Guarding only the series
     * leg left half the card's query surface exposed. This pins BOTH legs.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_window_leg_also_computes_while_holding_its_own_cold_lock(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new LprLockProbingHerdWidget;
        $widget->date = 'today';

        $total = new ReflectionMethod($widget, 'currentPeriodTotal');
        $total->setAccessible(true);
        $this->assertSame(7, $total->invoke($widget));

        $this->assertSame(1, $widget->windowCalls, 'precondition: the window cold path computed');
        $this->assertFalse(
            $widget->windowLockWasFreeDuringCompute,
            'the window compute must run while HOLDING its cold lock -- a free lock means the '
            .'window leg is still on the base bare cache()->remember() with no herd guard'
        );
    }

    /**
     * The window key must carry BOTH the resolved day and the viewer timezone.
     *
     * The day, because a "Today" value computed at 23:58 local would otherwise be served -- still
     * labelled Today -- into the next day. The timezone, because the day alone does not separate
     * viewers: at the instant frozen below, Chicago and London share the calendar day 2026-09-10
     * while their "today" windows cover different UTC instants, so on a shared org they would read
     * each other's totals. Round 12 (lane 1 H2) found the day segment shipped without the
     * timezone; UniqueScanCounts:208 and StagingLiveHitLeader:457 had already solved this.
     *
     * Recorded because the round-12 version of this test was CIRCULAR -- it built the expected key
     * by passing 'today:'.$day into buildWidgetCacheKey() and then asserted the result contained
     * $day, which is true of any implementation whatsoever. This one records the key the guard is
     * actually asked for and never constructs an expectation from the production helper.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_window_cache_key_carries_the_resolved_day_and_the_viewer_timezone(): void
    {
        // 12:00 Chicago (CDT) = 17:00 UTC = 18:00 London -- one shared calendar day, two
        // different 24h windows.
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'America/Chicago'));

        // User::timezone() is a get-only Attribute over the `dti` JSON column, so the viewer's
        // zone is set through dti -- matching UniqueScanCountsTest:342.
        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'America/Chicago']],
            role: RoleEnum::ADMIN->value
        );
        $this->actingAs($user);

        $chicago = new LprKeyRecordingHerdWidget;
        $chicago->date = 'today';
        $this->invokeWindow($chicago);

        $this->assertCount(1, $chicago->keys, 'precondition: the window leg asked the guard for one key');
        $this->assertStringContainsString(
            '2026-09-10',
            $chicago->keys[0],
            'the day-scoped filters must key on the RESOLVED day, or yesterday\'s total is served as Today'
        );
        $this->assertStringContainsString(
            ':tz=America/Chicago',
            $chicago->keys[0],
            'the window key is resolved from viewer-local wall clock, so it must carry the timezone'
        );

        // Same org, same user row, same instant, same local day -- only the timezone differs.
        $user->dti = \array_merge($user->dti ?: [], ['timeZone' => 'Europe/London']);
        $this->assertSame('Europe/London', $user->timezone, 'precondition: the viewer zone changed');

        $london = new LprKeyRecordingHerdWidget;
        $london->date = 'today';
        $this->invokeWindow($london);

        $this->assertStringContainsString('2026-09-10', $london->keys[0]);
        $this->assertNotSame(
            $chicago->keys[0],
            $london->keys[0],
            'two viewers on the SAME org sharing a local calendar day still have different "today" '
            .'windows, so they must not share a cache key'
        );
    }

    /**
     * The counterpart to the window-key test: the monthly series key must carry NEITHER the day
     * nor the timezone, because its value -- monthlySeries($orgId, now('UTC')) -- is identical for
     * every viewer of an org whatever their zone.
     *
     * This pins the fix for the regression round 13 introduced and all three review lanes flagged
     * (lane 1 H1). Adding `:tz=` to every key by overriding buildWidgetCacheKey() forked the cold
     * MUTEX per zone as well as the value, so cross-timezone viewers stopped serialising and this
     * card's most expensive leg (584M rows / 2.79 GB) multiplied by the org's distinct-zone count
     * for no correctness gain. Nothing caught it, which is why this test exists.
     *
     * The two viewers below are deliberately on opposite sides of the date line at the frozen
     * instant -- Chicago is still 2026-09-10 while Tokyo is already 2026-09-11 -- so the key must
     * be proven invariant even when the viewers' local CALENDAR DAYS differ, not merely their
     * offsets.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_monthly_series_key_is_shared_across_viewer_timezones(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'America/Chicago'));

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'America/Chicago']],
            role: RoleEnum::ADMIN->value
        );
        $this->actingAs($user);

        $chicago = new LprKeyRecordingHerdWidget;
        $chicago->date = 'this_month';
        $this->invokeMonthly($chicago);

        $user->dti = \array_merge($user->dti ?: [], ['timeZone' => 'Asia/Tokyo']);
        $this->assertSame('Asia/Tokyo', $user->timezone, 'precondition: the viewer zone changed');

        $tokyo = new LprKeyRecordingHerdWidget;
        $tokyo->date = 'this_month';
        $this->invokeMonthly($tokyo);

        $this->assertCount(1, $chicago->keys, 'precondition: the series leg asked the guard for one key');
        $this->assertStringNotContainsString(
            ':tz=',
            $chicago->keys[0],
            'the UTC monthly series is viewer-independent, so a timezone segment forks the value AND '
            .'the cold mutex per zone for no correctness gain'
        );
        $this->assertSame(
            $chicago->keys[0],
            $tokyo->keys[0],
            'two viewers of one org must SHARE the series key -- and therefore its cold lock -- even '
            .'when their local calendar days differ, or the herd guard stops serialising them'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_cold_compute_actually_runs_while_holding_the_cold_lock(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new LprLockProbingHerdWidget;
        $widget->date = 'this_month';

        $this->invokeMonthly($widget);

        $this->assertSame(1, $widget->calls, 'precondition: the cold path computed');
        $this->assertFalse(
            $widget->lockWasFreeDuringCompute,
            'the cold compute must run while HOLDING the cold lock -- a free lock means the herd guard is absent'
        );
    }

    /**
     * The refresh-lock expiry must reach Cache::flexible ITSELF, not merely be produced by the
     * seam. Review round 14 (lane 1 M1) showed the pre-existing test could not fail on the
     * mutation it claimed to guard: heldFlexible() calls flexibleRefreshLock() unconditionally and
     * only afterwards passes the result as the 4th argument, so a double recording the seam's
     * return proves the seam was CALLED. Deleting the 4th argument from both Cache::flexible calls
     * left that test green while flexible() fell back to `$lock['seconds'] ?? 0` -- never expires
     * -- wedging illuminate:cache:flexible:lock:{key} until the 24h hard TTL.
     *
     * This records the argument the consumer receives, by standing a Repository subclass in for
     * the cache. Nothing else in the guard changes: Repository::__call forwards lock() to the
     * store, and has() is native, so the warm-path and cold-path probes still work.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_refresh_lock_config_reaches_cache_flexible_itself(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $recorder = new FlexibleArgRecordingRepository(Cache::getStore());
        Cache::swap($recorder);

        $widget = new LprCountingHerdWidget;
        $widget->date = 'this_month';
        $this->invokeMonthly($widget);

        $this->assertNotEmpty(
            $recorder->calls,
            'precondition: the guard actually reached Cache::flexible'
        );
        $this->assertSame(
            ['seconds' => LprRepossessions::REFRESH_LOCK_SECONDS],
            $recorder->calls[0],
            'the lock config must arrive as Cache::flexible\'s 4th argument -- omitting it makes '
            .'flexible() fall back to no expiry, so a worker killed mid-refresh wedges the key '
            .'until the hard TTL'
        );
    }

    /**
     * The window leg's bounds must be re-anchored in the VIEWER's timezone before the query
     * converts them to UTC. Review round 14 (lane 3 M4) found this entirely unpinned: every stub
     * subclass overrides fetchWindowTotal() and discards $start/$end, so dropping the $timezone
     * argument from Carbon::parse() survived all 86 tests while shifting a Chicago viewer's
     * "Today" window five hours -- the exact defect getModelQueryForDates()'s docblock says it
     * exists to prevent.
     *
     * Asserting the UTC instant, not just the zone name: a Chicago local midnight is 05:00 UTC in
     * CDT, so the naive-string mutant reads 00:00 UTC and this fails.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_window_bounds_are_re_anchored_in_the_viewers_timezone(): void
    {
        Carbon::setTestNow('2026-09-10 18:00:00');

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'America/Chicago']],
            role: RoleEnum::ADMIN->value
        );
        $this->actingAs($user);
        $this->assertSame('2026-09-10', now(tz: $user->timezone)->format('Y-m-d'), 'precondition: same local day');

        $widget = new WindowBoundsRecordingLprRepossessions;
        $widget->date = 'today';

        $total = new ReflectionMethod($widget, 'currentPeriodTotal');
        $total->setAccessible(true);
        $total->invoke($widget);

        $this->assertNotNull($widget->capturedStart, 'precondition: the window leg computed');
        $this->assertSame(
            '2026-09-10 05:00',
            $widget->capturedStart->clone()->utc()->format('Y-m-d H:i'),
            'Chicago local midnight is 05:00 UTC in CDT -- parsing the naive string without the '
            .'viewer timezone reads it as 00:00 UTC and shifts the whole window five hours'
        );
        $this->assertSame(
            'America/Chicago',
            $widget->capturedStart->timezone->getName(),
            'the bound must carry the viewer zone, not the app default'
        );
    }

    /**
     * REGRESSION-class guard (DEV-2463 review round 3, lane 3 M2, inherited via the
     * shared HeldFlexibleCache trait): a half-populated flexible key must be treated as a
     * miss and take the lock.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_a_half_populated_flexible_key_is_treated_as_a_miss_and_takes_the_lock(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $warm = new LprLockProbingHerdWidget;
        $warm->date = 'this_month';
        $this->invokeMonthly($warm);
        $this->assertSame(1, $warm->calls, 'precondition: warmed');

        // The key production actually used, recorded by the probe -- not rebuilt here.
        $key = $warm->currentKey;
        $this->assertTrue(cache()->has($key), 'precondition: the value key is present');

        cache()->forget('illuminate:cache:flexible:created:'.$key);

        $probe = new LprLockProbingHerdWidget;
        $probe->date = 'this_month';
        $this->invokeMonthly($probe);

        $this->assertSame(1, $probe->calls, 'a half-populated key must recompute -- flexible() treats it as a miss');
        $this->assertFalse(
            $probe->lockWasFreeDuringCompute,
            'that recompute must be SERIALISED: testing only the value key skipped the lock entirely'
        );
    }

    /**
     * DEV-2651 t4. The absorbed unique-scans-to-lpr-repossession card had ZERO
     * UNAVAILABLE_VALUE/numeratorUnavailable occurrences and rendered a fabricated ratio on a
     * numerator failure. RatioAgainstUniqueScans (t1) is what fixes that: the headline (this
     * card's own db_repos denominator, unaffected) still renders, the ratio degrades to
     * UNAVAILABLE_VALUE, and the chart drops the ratio series rather than plotting a fabricated
     * number.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_a_numerator_failure_degrades_the_ratio_but_not_the_headline(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        cache()->flush();
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RatioDegradingLprRepossessions;
        $widget->date = 'this_month';
        $widget->reposSeries = ['2026-08-01' => ['deduped' => 50, 'db_repos' => 10]];

        $stat = $widget->toStat();

        $this->assertSame('10', $stat->getValue(), 'the headline reads the healthy denominator, unaffected by the numerator failure');
        $this->assertStringContainsString(
            LprRepossessions::UNAVAILABLE_VALUE,
            (string) $stat->getDescription(),
            'a degraded numerator must render the fail-safe placeholder, never a fabricated ratio'
        );

        $data = $widget->getData();
        $this->assertCount(
            1,
            $data['datasets'],
            'getData() must suppress the ratio dataset entirely on a degraded numerator'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_stat_description_reports_scans_per_lpr_repo(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        cache()->flush();
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RatioStubbingLprRepossessions;
        $widget->date = 'this_month';
        $widget->reposSeries = ['2026-08-01' => ['deduped' => 50, 'db_repos' => 10]];
        $widget->scansBatch = ['2026-08' => 42];

        $stat = $widget->toStat();

        $this->assertSame('10', $stat->getValue(), 'AC-2: the headline stays the LPR repo count');
        $this->assertSame('4.2 scans per LPR repo', (string) $stat->getDescription());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_get_data_renders_two_datasets_on_separate_y_axes(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        cache()->flush();
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RatioStubbingLprRepossessions;
        $widget->date = 'this_month';
        $widget->reposSeries = ['2026-08-01' => ['deduped' => 50, 'db_repos' => 10]];
        $widget->scansBatch = ['2026-08' => 42];

        $data = $widget->getData();

        $this->assertCount(2, $data['datasets'], 'AC-3: two datasets once the numerator is healthy');
        $this->assertSame('y', $data['datasets'][0]['yAxisID']);
        $this->assertSame('y1', $data['datasets'][1]['yAxisID']);
        $this->assertSame(4.2, $data['datasets'][1]['data'][12]);
    }

    /**
     * Human-QA finding (2026-09-22). makeDataset() colours a series with a PER-MONTH array,
     * and Chart.js builds each legend swatch from index 0 of it -- the LEFTMOST month, which
     * is never the highlighted one. The legend therefore showed grey beside accent-coloured
     * bars from the moment the ratio series turned a legend on. Identical defect to the one
     * DashboardCardMetricsTest pins for the hit cards; the ratio cards were never covered.
     *
     * The same finding rejected the ratio as a SECOND BAR: two bars of equal height on two
     * axes read as one unit. `type => 'line'` is what carries "this is a rate, not a count".
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_the_count_series_is_one_colour_and_the_ratio_is_a_line(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        cache()->flush();
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RatioStubbingLprRepossessions;
        $widget->date = 'this_month';
        $widget->reposSeries = ['2026-08-01' => ['deduped' => 50, 'db_repos' => 10]];
        $widget->scansBatch = ['2026-08' => 42];

        [$count, $ratio] = $widget->getData()['datasets'];

        foreach (['backgroundColor', 'hoverBackgroundColor', 'borderColor', 'hoverBorderColor'] as $key) {
            $this->assertIsString(
                $count[$key],
                "count series '{$key}' must be ONE colour; a per-month array puts the leftmost month in the legend"
            );
        }

        $this->assertSame('line', $ratio['type'], 'the ratio must not be a second bar');
        $this->assertNotSame($count['backgroundColor'], $ratio['backgroundColor']);

        $this->assertLessThan(
            $count['order'],
            $ratio['order'],
            'Chart.js draws datasets in REVERSE index order, so the bars bury the line unless the line carries a lower order'
        );
    }

    /**
     * The flip side: with no second series there is no legend to be wrong about, and the
     * current-month highlight is still worth having. Guards the branch, not just the colour.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_a_degraded_numerator_keeps_the_per_month_highlight_on_the_lone_series(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new RatioDegradingLprRepossessions;
        $widget->reposSeries = ['2026-08-01' => ['deduped' => 10, 'db_repos' => 40]];

        $datasets = $widget->getData()['datasets'];

        $this->assertCount(1, $datasets);
        $this->assertIsArray($datasets[0]['backgroundColor'], 'the month highlight survives on a single-series chart');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_legend_is_shown_only_when_expanded(): void
    {
        // Review-4 R4-B: the legend is now also conditional on the ratio being available, so
        // this needs an entitled viewer and a healthy numerator. Previously it ran with no
        // authenticated user at all.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new RatioStubbingLprRepossessions;
        $method = new ReflectionMethod($widget, 'getOptions');
        $method->setAccessible(true);

        $widget->expanded = false;
        $this->assertFalse($method->invoke($widget)['plugins']['legend']['display']);

        $widget->expanded = true;
        $this->assertTrue($method->invoke($widget)['plugins']['legend']['display']);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_get_options_adds_a_labelled_right_hand_y_axis_for_the_ratio(): void
    {
        // Review-4 R4-B: see above — the axis is gated on canViewRatioFigure() plus a healthy
        // numerator now, so an unauthenticated bare widget can no longer assert it.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new RatioStubbingLprRepossessions;
        $method = new ReflectionMethod($widget, 'getOptions');
        $method->setAccessible(true);

        $options = $method->invoke($widget);

        $this->assertSame('right', $options['scales']['y1']['position']);
        $this->assertFalse($options['scales']['y1']['grid']['drawOnChartArea']);
        $this->assertNotEmpty($options['scales']['y1']['title']['text'] ?? null, 'the ratio axis must be labelled');
        $this->assertNotEmpty($options['scales']['y']['title']['text'] ?? null, 'the count axis must be labelled');
    }

    /**
     * Review-4 R4-B. getData() already drops the ratio dataset on a failed numerator; the axis
     * and legend have to drop with it, or a degraded card renders a titled right-hand axis with
     * nothing plotted against it and a one-entry legend. RecoveryCounts has always done this.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_a_degraded_numerator_drops_the_right_hand_axis_and_the_legend(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new RatioDegradingLprRepossessions;
        $widget->reposSeries = ['2026-08-01' => ['deduped' => 10, 'db_repos' => 40]];
        $widget->expanded = true;

        $this->assertCount(1, $widget->getData()['datasets'], 'precondition: the ratio dataset is dropped');

        $method = new ReflectionMethod($widget, 'getOptions');
        $method->setAccessible(true);
        $options = $method->invoke($widget);

        $this->assertArrayNotHasKey('y1', $options['scales'], 'no axis for a series that is not drawn');
        $this->assertFalse($options['plugins']['legend']['display'], 'no legend for a single series');
    }

    private function invokeMonthly(LprRepossessions $widget): array
    {
        $m = new ReflectionMethod($widget, 'getMonthlyData');
        $m->setAccessible(true);

        return $m->invoke($widget, false);
    }

    private function invokeWindow(LprRepossessions $widget): int
    {
        $m = new ReflectionMethod($widget, 'currentPeriodTotal');
        $m->setAccessible(true);

        return $m->invoke($widget);
    }

    /**
     * Review-1 M1. The expanded card is a long-lived Livewire component, so a numerator
     * failure that sets $numeratorUnavailable must not survive the next refresh-dashboard.
     * Without the override this card lacked, the ratio stayed dropped under an empty
     * right-hand axis for the life of the component -- long after the 60s backoff expired.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_refresh_data_resets_the_numerator_unavailable_flag_and_the_ratio_memos(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new RefreshProbingLprRepossessions;
        $widget->reposSeries = ['2026-08-01' => ['deduped' => 10, 'db_repos' => 40]];
        $widget->date = 'this_month';

        $flag = new \ReflectionProperty(LprRepossessions::class, 'numeratorUnavailable');
        $flag->setAccessible(true);

        // The failing numerator degrades the ratio and drops the second dataset.
        $this->assertSame(LprRepossessions::UNAVAILABLE_VALUE.' scans per LPR repo', (string) $widget->toStat()->getDescription());
        $this->assertTrue($flag->getValue($widget));
        $this->assertCount(1, $widget->getData()['datasets'], 'the ratio series must be dropped while the numerator is unavailable');

        // ClickHouse recovers, and the viewer switches the date filter.
        $widget->numeratorThrows = false;
        $widget->refreshData('last_7d');

        $this->assertFalse($flag->getValue($widget), 'a numerator failure must not stick past a refresh-dashboard event');

        foreach (['windowRatioMemo', 'ratioMonthlyDataMemo'] as $memo) {
            $p = new \ReflectionProperty(LprRepossessions::class, $memo);
            $p->setAccessible(true);
            $this->assertNull($p->getValue($widget), "{$memo} must be cleared so a recovered numerator is re-read");
        }
    }

    /**
     * DEV-2652 t3. The numerator follows Chicago (span ends in July). getMonthlyData()
     * stays on the UTC August db_repos bucket. The funnel key stays free of :tz=.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_ratio_numerator_uses_chicago_while_db_repos_stays_on_utc_august(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $this->assertSame('2026-08', now('UTC')->format('Y-m'), 'precondition: UTC is already August');
        $this->assertSame('2026-07', now(tz: $user->timezone)->format('Y-m'), 'precondition: the viewer is still in July');

        $widget = new RecordingNumeratorLprRepossessions;
        $widget->reposSeries = [
            '2026-08-01' => ['deduped' => 999, 'db_repos' => 777],
        ];

        $this->invoke($widget, 'getRatioMonthlyData', []);

        $this->assertSame('America/Chicago', $widget->scansTimezone);
        $this->assertSame('2026-07', Carbon::parse((string) $widget->scansEnd)->format('Y-m'));
        $this->assertSame(777, $this->invokeMonthly($widget)[12], 'the headline bucket stays the UTC August db_repos figure');

        $funnelKey = LprRepossessionsQuery::sharedMonthlySeriesCacheKey($user->org_id, '2026-08');
        $this->assertStringNotContainsString(':tz=', $funnelKey);
    }

    /**
     * CST/PST/+05:00 become UTC. US/Central is kept when this PHP can construct it
     * and otherwise becomes America/Chicago. getRatioMonthlyData() must not throw
     * for either.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeViewerZones(): array
    {
        return [
            'CST' => ['CST', 'UTC'],
            'PST' => ['PST', 'UTC'],
            '+05:00' => ['+05:00', 'UTC'],
            'US/Central' => ['US/Central', self::expectedLegacyTimezone('US/Central')],
        ];
    }

    /**
     * Alias when this PHP can construct it; otherwise the canonical IANA id.
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

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    #[DataProvider('unsafeViewerZones')]
    public function test_ratio_numerator_records_the_sanitised_zone_for_an_unsafe_viewer_zone(string $zone, string $expected): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => $zone]],
            role: RoleEnum::ADMIN->value,
        );
        $this->actingAs($user);

        $widget = new RecordingNumeratorLprRepossessions;

        $this->invoke($widget, 'getRatioMonthlyData', []);

        $this->assertSame($expected, $widget->scansTimezone);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_flexible_pair_comes_from_the_ladder_and_the_shared_keys_stay(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new LprTtlRecordingWidget;
        $widget->date = 'today';
        (new ReflectionMethod($widget, 'getMonthlyData'))->invoke($widget, false);
        (new ReflectionMethod($widget, 'currentPeriodTotal'))->invoke($widget);

        $this->assertSame([[600, 600], [600, 600]], $widget->ttls);
        $this->assertNotSame([3600, 86400], $widget->ttls[0]);

        $seriesKey = $widget->keys[0];
        $this->assertSame(
            LprRepossessionsQuery::sharedMonthlySeriesCacheKey($user->org_id, '2026-08'),
            $seriesKey
        );
        $this->assertStringNotContainsString(':tz=', $seriesKey);
        $this->assertStringNotContainsString('LprRepossessions', $seriesKey);
        $this->assertStringNotContainsString('LprRepossessions', $widget->keys[1]);

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $impersonated = new LprTtlRecordingWidget;
        $impersonated->date = 'today';
        (new ReflectionMethod($impersonated, 'getMonthlyData'))->invoke($impersonated, false);

        $this->assertSame([[10, 10]], $impersonated->ttls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_export_action_is_csv_only_and_does_not_cap_rows(): void
    {
        Carbon::setTestNow('2026-08-01 02:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->assertSame('America/Chicago', $user->timezone);
        $this->actingAs($user);

        $widget = new CountingLprRepossessions;
        $widget->expanded = true;
        $action = $widget->exportAction();

        $this->assertInstanceOf(HasActions::class, $widget);
        $this->assertTrue($action->isVisible());
        $this->assertSame(LprRepossessionExporter::class, $action->getExporter());
        $this->assertNull($action->getFormats());
        $this->assertFalse($action->hasColumnMapping());
        $this->assertFalse($action->shouldOpenModal());
        $this->assertSame(10000, $action->getChunkSize());
        $this->assertNull($action->getMaxRows());
        $this->assertSame('lpr-repossessions-claimed-2026-08-01-to-2026-08-31', $action->getFileName(new Export));
        $this->assertSame(0, $widget->monthlySeriesCalls);
        $this->assertSame(0, $widget->windowCalls);

        $view = (new \ReflectionClass(LprRepossessions::class))->getProperty('view');
        $this->assertSame('filament-widgets::chart-widget', $view->getValue($widget));
        $this->assertFileDoesNotExist(resource_path('views/filament/widgets/dashboard/lpr-repossessions.blade.php'));

        $collapsed = new CountingLprRepossessions;
        $this->assertSame('LPR Repossessions', $collapsed->getHeading());

        $heading = $widget->getHeading();
        $this->assertInstanceOf(Htmlable::class, $heading);
        $html = $heading->toHtml();
        $this->assertStringContainsString('LPR Repossessions', $html);
        $this->assertStringContainsString("mountAction('export')", $html);
        $this->assertSame(0, $widget->monthlySeriesCalls);
        $this->assertSame(0, $widget->windowCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_export_window_uses_utc_month_buckets_and_viewer_local_days(): void
    {
        Carbon::setTestNow('2026-08-01 02:00:00');

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new CountingLprRepossessions;

        $widget->date = 'this_month';
        [$start, $end] = $this->invoke($widget, 'exportWindow', []);
        $this->assertTrue($start->equalTo(Carbon::parse('2026-08-01 00:00:00', 'UTC')));
        $this->assertTrue($end->equalTo(Carbon::parse('2026-08-31', 'UTC')->endOfMonth()));
        $this->assertSame('UTC', $start->timezone->getName());

        $widget->date = 'last_month';
        [$start, $end] = $this->invoke($widget, 'exportWindow', []);
        $this->assertTrue($start->equalTo(Carbon::parse('2026-07-01 00:00:00', 'UTC')));
        $this->assertTrue($end->equalTo(Carbon::parse('2026-07-31', 'UTC')->endOfMonth()));

        $widget->date = 'today';
        [$start, $end] = $this->invoke($widget, 'exportWindow', []);
        $this->assertSame('2026-07-31 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-07-31 23:59:59', $end->toDateTimeString());
        $this->assertSame('America/Chicago', $start->timezone->getName());
        $this->assertSame('America/Chicago', $end->timezone->getName());

        $widget->date = 'last_7d';
        [$start, $end] = $this->invoke($widget, 'exportWindow', []);
        $this->assertSame('2026-07-24 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-07-31 23:59:59', $end->toDateTimeString());
        $this->assertSame('America/Chicago', $start->timezone->getName());

        $sql = $this->invoke($widget, 'exportRowsSql', []);
        $this->assertStringContainsString('org_id = '.$user->org_id, $sql);
        $this->assertStringContainsString('agent_org_id = '.$user->org_id, $sql);
        $this->assertStringNotContainsString('cityHash64', $sql);
        $this->assertSame(0, $widget->monthlySeriesCalls);
        $this->assertSame(0, $widget->windowCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    #[DataProvider('exportWindowLabels')]
    public function test_export_file_name_and_tooltip_follow_the_export_window(string $now, string $date, string $fileName, string $tooltip): void
    {
        Carbon::setTestNow($now);

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new CountingLprRepossessions;
        $widget->expanded = true;
        $widget->date = $date;
        $action = $widget->exportAction();

        $this->assertSame($fileName, $action->getFileName(new Export));
        $this->assertSame($tooltip, $action->getTooltip());
        $this->assertSame(0, $widget->monthlySeriesCalls);
        $this->assertSame(0, $widget->windowCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('lpr-repossessions')]
    public function test_export_tooltip_follows_the_date_toggle(): void
    {
        Carbon::setTestNow('2026-10-07 15:00:00');

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new CountingLprRepossessions;
        $widget->expanded = true;

        $this->assertSame("Export Oct '26 LPR repos (cases claimed Oct 1\u{2013}31) as CSV", $widget->exportAction()->getTooltip());

        $widget->refreshData('today');
        $action = $widget->exportAction();
        $this->assertSame('Export today (Oct 7)', $action->getTooltip());
        $this->assertSame('lpr-repossessions-claimed-2026-10-07', $action->getFileName(new Export));

        $widget->refreshData('last_7d');
        $this->assertSame("Export last 7 days (Sep 30\u{2013}Oct 7)", $widget->exportAction()->getTooltip());

        $heading = $widget->getHeading();
        $this->assertInstanceOf(Htmlable::class, $heading);
        $this->assertStringContainsString('Export last 7 days (Sep 30', $heading->toHtml());

        $this->assertSame(0, $widget->monthlySeriesCalls);
        $this->assertSame(0, $widget->windowCalls);
    }

    /**
     * Times are UTC. Chicago is CDT (UTC-5) in Aug/Oct 2026 and CST (UTC-6) in Jan 2027.
     * Tooltips use "\u{2013}" so a hyphen in production cannot pass by being mirrored.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function exportWindowLabels(): array
    {
        return [
            'T1 this_month' => ['2026-10-07 15:00:00', 'this_month', 'lpr-repossessions-claimed-2026-10-01-to-2026-10-31', "Export Oct '26 LPR repos (cases claimed Oct 1\u{2013}31) as CSV"],
            'T2 last_month' => ['2026-10-07 15:00:00', 'last_month', 'lpr-repossessions-claimed-2026-09-01-to-2026-09-30', "Export Sep '26 LPR repos (cases claimed Sep 1\u{2013}30) as CSV"],
            'T3 last_7d' => ['2026-10-07 15:00:00', 'last_7d', 'lpr-repossessions-claimed-2026-09-30-to-2026-10-07', "Export last 7 days (Sep 30\u{2013}Oct 7)"],
            'T4 today' => ['2026-10-07 15:00:00', 'today', 'lpr-repossessions-claimed-2026-10-07', 'Export today (Oct 7)'],
            'E1 this_month' => ['2026-08-01 02:00:00', 'this_month', 'lpr-repossessions-claimed-2026-08-01-to-2026-08-31', "Export Aug '26 LPR repos (cases claimed Aug 1\u{2013}31) as CSV"],
            'E2 last_month' => ['2026-08-01 02:00:00', 'last_month', 'lpr-repossessions-claimed-2026-07-01-to-2026-07-31', "Export Jul '26 LPR repos (cases claimed Jul 1\u{2013}31) as CSV"],
            'E3 last_7d' => ['2026-08-01 02:00:00', 'last_7d', 'lpr-repossessions-claimed-2026-07-24-to-2026-07-31', "Export last 7 days (Jul 24\u{2013}31)"],
            'E4 today' => ['2026-08-01 02:00:00', 'today', 'lpr-repossessions-claimed-2026-07-31', 'Export today (Jul 31)'],
            'Y1 last_7d' => ['2027-01-05 18:00:00', 'last_7d', 'lpr-repossessions-claimed-2026-12-29-to-2027-01-05', "Export last 7 days (Dec 29\u{2013}Jan 5)"],
        ];
    }

    private function invoke(object $widget, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($widget, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($widget, $args);
    }
}

/**
 * Counts the two seams so the round-trip AC can be pinned deterministically. Returns
 * empty/zero results -- the assertions are about HOW MANY trips happen, not their content.
 */
class CountingLprRepossessions extends LprRepossessions
{
    public int $monthlySeriesCalls = 0;

    public int $windowCalls = 0;

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        $this->monthlySeriesCalls++;

        return [];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        $this->windowCalls++;

        return 0;
    }
}

/** Returns a caller-supplied series instead of hitting ClickHouse, for bucket/stage assertions. */
class StubbingLprRepossessions extends LprRepossessions
{
    /** @var array<string, array{deduped: int, db_repos: int}> */
    public array $series = [];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->series;
    }
}

/** Isolates the runWindowQuery() -> fetchWindowTotal() stage-selection step. */
class StageSelectingLprRepossessions extends LprRepossessions
{
    protected function runWindowQuery(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        return ['deduped' => 111, 'db_repos' => 222];
    }
}

/** Proves a failure propagates rather than rendering as a fabricated 0. */
class ThrowingLprRepossessions extends LprRepossessions
{
    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        throw new \RuntimeException('simulated ClickHouse failure');
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        throw new \RuntimeException('simulated ClickHouse failure');
    }
}

/** Counts real computes so the guard's cache behaviour is observable without ClickHouse. */
class LprCountingHerdWidget extends LprRepossessions
{
    public int $calls = 0;

    /** The key heldFlexible() was actually asked for -- see LprLockProbingHerdWidget::$currentKey. */
    public ?string $currentKey = null;

    protected function heldFlexible(string $key, array $ttl, \Closure $compute): mixed
    {
        $this->currentKey = $key;

        return parent::heldFlexible($key, $ttl, $compute);
    }

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        $this->calls++;

        return [$through->clone()->utc()->format('Y-m-01') => ['deduped' => 3, 'db_repos' => 2]];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return 0;
    }
}

/** Reports whether the cold lock was free at the moment the cold compute ran. */
class LprLockProbingHerdWidget extends LprRepossessions
{
    public int $calls = 0;

    public bool $lockWasFreeDuringCompute = true;

    public int $windowCalls = 0;

    public bool $windowLockWasFreeDuringCompute = true;

    /**
     * The key heldFlexible() was actually asked for on the leg currently computing. The probes
     * below derive the cold-lock name from THIS rather than from a key the test rebuilds through
     * buildWidgetCacheKey(): a rebuilt expectation duplicates production key logic, and when
     * round 13 moved the `:tz=` segment off the series key it went stale and the probe silently
     * checked a lock nothing had taken. (It failed rather than passed, which is the safe
     * direction -- but deriving it removes the duplication instead of re-syncing it.)
     */
    public ?string $currentKey = null;

    protected function heldFlexible(string $key, array $ttl, \Closure $compute): mixed
    {
        $this->currentKey = $key;

        return parent::heldFlexible($key, $ttl, $compute);
    }

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        $this->calls++;
        $this->lockWasFreeDuringCompute = $this->probeCurrentColdLock();

        return [$through->clone()->utc()->format('Y-m-01') => ['deduped' => 3, 'db_repos' => 2]];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        $this->windowCalls++;
        $this->windowLockWasFreeDuringCompute = $this->probeCurrentColdLock();

        return 7;
    }

    /** True if the cold lock for the leg being computed is FREE -- i.e. the guard is absent. */
    private function probeCurrentColdLock(): bool
    {
        $probe = Cache::lock($this->currentKey.':cold', 5);
        $free = $probe->get();

        if ($free) {
            $probe->release();
        }

        return $free;
    }
}

/**
 * Records the cache keys the guarded legs actually ask heldFlexible() for, so a key-shape
 * assertion reads the PRODUCTION key rather than one the test rebuilt for itself. Bypasses the
 * cache deliberately: the subject here is the key, not the caching.
 */
class LprKeyRecordingHerdWidget extends LprRepossessions
{
    /** @var array<int, string> */
    public array $keys = [];

    protected function heldFlexible(string $key, array $ttl, \Closure $compute): mixed
    {
        $this->keys[] = $key;

        return $compute();
    }

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return [];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return 0;
    }
}

/**
 * Stands in for the cache repository so a test can record the arguments Cache::flexible actually
 * receives. Extends the real Repository and delegates, so the guard's has()/lock() calls behave
 * exactly as in production (Repository::__call forwards lock() to the store).
 */
class FlexibleArgRecordingRepository extends Repository
{
    /** @var array<int, array|null> the 4th argument of each Cache::flexible call, in order */
    public array $calls = [];

    public function flexible($key, $ttl, $callback, $lock = null, $alwaysDefer = false)
    {
        $this->calls[] = $lock;

        return parent::flexible($key, $ttl, $callback, $lock, $alwaysDefer);
    }
}

/** Captures the Carbon bounds getModelQueryForDates() produces, which every other double discards. */
class WindowBoundsRecordingLprRepossessions extends LprRepossessions
{
    public ?CarbonInterface $capturedStart = null;

    public ?CarbonInterface $capturedEnd = null;

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        $this->capturedStart = $start;
        $this->capturedEnd = $end;

        return 0;
    }

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return [];
    }
}

/**
 * Stubs both legs -- the denominator via LprRepossessions' own seams, the numerator via
 * RatioAgainstUniqueScans' innermost seams -- with fixed, healthy values.
 */
class RatioStubbingLprRepossessions extends LprRepossessions
{
    /** @var array<string, array{deduped: int, db_repos: int}> */
    public array $reposSeries = [];

    public int $reposWindow = 0;

    /** @var array<string, int> */
    public array $scansBatch = [];

    /** @var array{totals: array<int, int>, names: array<int, string>} */
    public array $scansRange = ['totals' => [0], 'names' => []];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->reposSeries;
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->reposWindow;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        return $this->scansBatch;
    }

    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return $this->scansRange;
    }
}

/**
 * The degrade pin's double: a healthy denominator (LprRepossessions' own seams) paired with a
 * numerator that fails at RatioAgainstUniqueScans' two innermost seams, exactly as a real
 * ClickHouse outage would surface to the trait.
 */
class RefreshProbingLprRepossessions extends LprRepossessions
{
    /** @var array<string, array{deduped: int, db_repos: int}> */
    public array $reposSeries = [];

    public bool $numeratorThrows = true;

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->reposSeries;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        if ($this->numeratorThrows) {
            throw new \RuntimeException('simulated ClickHouse failure (numerator)');
        }

        return [];
    }
}

class RatioDegradingLprRepossessions extends LprRepossessions
{
    /** @var array<string, array{deduped: int, db_repos: int}> */
    public array $reposSeries = [];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->reposSeries;
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return 0;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        throw new \RuntimeException('simulated ClickHouse failure (numerator)');
    }

    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        throw new \RuntimeException('simulated ClickHouse failure (numerator)');
    }
}

/** Records the zone and span that reach the unique-scans numerator. Never touches ClickHouse. */
class RecordingNumeratorLprRepossessions extends LprRepossessions
{
    /** @var array<string, array{deduped: int, db_repos: int}> */
    public array $reposSeries = [];

    public ?string $scansEnd = null;

    public ?string $scansTimezone = null;

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->reposSeries;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->scansEnd = $end;
        $this->scansTimezone = $timezone;

        return [];
    }

    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return ['totals' => [0], 'names' => []];
    }
}

class LprTtlRecordingWidget extends LprRepossessions
{
    /** @var array<int, string> */
    public array $keys = [];

    /** @var array<int, array{0: int, 1: int}> */
    public array $ttls = [];

    protected function heldFlexible(string $key, array $ttl, \Closure $compute): mixed
    {
        $this->keys[] = $key;
        $this->ttls[] = $ttl;

        return $compute();
    }

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return [];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return 0;
    }
}
