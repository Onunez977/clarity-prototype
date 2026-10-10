<?php

declare(strict_types=1);

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Enums\FeatureEnum;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\LprRepossessions;
use App\Filament\Widgets\Dashboard\RecoveryCounts;
use App\Filament\Widgets\Dashboard\UniqueLprCaseClaims;
use App\Filament\Widgets\Dashboard\UniqueScanCounts;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class UniqueScanCountsTest extends TestCase
{
    use StatHelper;

    protected function setUp(): void
    {
        parent::setUp();

        // S6: several tests here call now()/now(tz:) more than once while resolving
        // month-arithmetic cache keys; pinned to a fixed mid-month instant so none of
        // them can straddle a month boundary mid-test.
        Carbon::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_can_render(): void
    {
        $user = $this->actingAsProUser();
        $widget = new UniqueScanCounts;

        cache()->put($this->cacheKeyWithTz(
            key: now(tz: ScanPlateStatsBatchQuery::safeTimezone($user->timezone ?? 'UTC'))->format('Y-m'),
            orgId: $user->org_id,
            timezone: ScanPlateStatsBatchQuery::safeTimezone($user->timezone ?? 'UTC'),
        ), 0);

        // DEV-2651 t2: the ported total leg's current-month bucket has the SAME
        // no-try/catch contract as ScanCount's own resolveCurrentMonthFromHot() -- a real
        // render must not fall through to it uncached, or it throws exactly as ScanCount's
        // own render would. Mirrors ScanCountTest::test_can_render()'s seed.
        cache()->put(
            (new ReflectionMethod($widget, 'buildWidgetCacheKey'))->invoke($widget, 'scan-total:this_month', $user->org_id),
            0
        );

        Livewire::test(UniqueScanCounts::class)
            ->assertSuccessful()
            ->assertSeeText('Scan Counts');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(UniqueScanCounts::class)
            ->assertForbidden()
            ->assertDontSeeText('Scan Counts');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_stat_color_is_success_not_the_gray_default(): void
    {
        $this->actingAsProUser();

        // Q1: deliberately the SAME colour as scan-count, so the two scan-family cards
        // read as one group -- not a copy-paste slip.
        $this->assertSame('success', (new UniqueScanCounts)->getStatColor());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_admin_on_non_entitled_org_can_view_card_data(): void
    {
        // DEV-2651 t2 pin 1 -- decision 2: unique-scan-counts is ungated entirely. Before
        // this ticket, canViewCardData() ANDed the role check with
        // FeatureEnum::COMPANY_METRICS, so an ADMIN with no Company Metrics entitlement
        // was refused. This is the whole of the decision, pinned directly: no Pro grant
        // at all, yet the card's data must be viewable.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $this->assertTrue(UniqueScanCounts::canViewCardData());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_non_entitled_org_is_no_longer_locked_now_that_the_card_is_ungated(): void
    {
        // Formerly test_non_entitled_org_renders_locked_and_issues_zero_clickhouse_calls,
        // which pinned the OPPOSITE: a non-entitled ADMIN got the locked placeholder and
        // zero ClickHouse calls. DEV-2651 decision 2 removed that gate, so the same org
        // now reaches the real (unlocked) stat and both series' fetch seams actually run
        // -- this drives the real CompanyMetricsOverview::getStats() seam, not
        // canViewCardData() in isolation, the same way the superseded test did.
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $this->assertTrue(UniqueScanCounts::canViewCardData());

        $overview = new OverviewWithCountingUniqueScanCounts;

        $method = new ReflectionMethod($overview, 'getStats');
        $method->setAccessible(true);

        /** @var array<Stat> $stats */
        $stats = $method->invoke($overview);

        $this->assertCount(1, $stats);

        $stat = $stats[0];
        $this->assertNotSame(DashboardMetricCard::LOCKED_VALUE, $stat->getValue());
        $this->assertNotNull($stat->getChart(), 'an unlocked card must carry its sparkline');

        $widget = $overview->capturedWidget;
        $this->assertInstanceOf(CountingUniqueScanCounts::class, $widget);
        $this->assertSame(1, $widget->monthlySeriesCalls);
        $this->assertSame(1, $widget->rangeRollupCalls);
        $this->assertSame(1, $widget->totalMonthlySeriesCalls, 'the ported total leg must also run for a non-entitled org now that the card is ungated');
        $this->assertSame(1, $widget->totalCurrentMonthCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_admin_impersonator_still_can_view_card_data_now_the_gate_is_gone(): void
    {
        // The Pro gate admin_impersonator used to bypass no longer exists (see the pin
        // above) -- this only confirms an impersonation session has no effect either way,
        // so the sibling assertion above is not accidentally relying on session state.
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $this->assertTrue(UniqueScanCounts::canViewCardData());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_fully_cold_render_calls_each_fetch_seam_exactly_once(): void
    {
        // AC: per-render ClickHouse round-trip count must not regress. With NOTHING
        // seeded, all 12 past months are missing, so a per-month fan-out would call the
        // monthly seam 12 times. Counting the seams is the only deterministic option --
        // DB::listen never fires for the ClickHouse driver.
        $this->actingAsProUser();

        $widget = new CountingUniqueScanCounts;
        $widget->date = 'this_month';

        (new ReflectionMethod($widget, 'getMonthlyData'))->invoke($widget, false);

        $this->assertSame(1, $widget->monthlySeriesCalls, 'the 12 past months must be ONE batched call, never 12');
        $this->assertSame(1, $widget->rangeRollupCalls, 'the current month is ONE range call');

        // A second resolve on the same instance adds no further trips (memoized).
        (new ReflectionMethod($widget, 'getMonthlyData'))->invoke($widget, false);
        $this->assertSame(1, $widget->monthlySeriesCalls);
        $this->assertSame(1, $widget->rangeRollupCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_for_today_issues_exactly_four_clickhouse_round_trips(): void
    {
        // S12, extended by DEV-2651 t2: toStat() now also resolves getTotalScanCount() for
        // the description line. For 'today' that is ONE additional range fetch
        // (currentPeriodScanTotal(), under the ported total's own namespace) on top of the
        // pre-existing three: one batched past-months call, one this_month range rollup
        // via chartSeries()'s getMonthlyData(), and a third range rollup for 'today' itself
        // via getCardTotal()'s currentPeriodTotal().
        $this->actingAsProUser();

        $widget = new CountingUniqueScanCounts;
        $widget->date = 'today';

        $widget->toStat();

        $this->assertSame(1, $widget->monthlySeriesCalls, 'the 12 past months must be ONE batched call');
        $this->assertSame(2, $widget->rangeRollupCalls, 'this_month (via the sparkline) + today (via the headline) are two separate range rollups');
        $this->assertSame(1, $widget->totalRangeTotalCalls, "the ported total's today headline is ONE range fetch, cached under its own namespace");
        $this->assertSame(0, $widget->totalMonthlySeriesCalls, "today/last_7d never touch the ported total's monthly buckets");
        $this->assertSame(0, $widget->totalCurrentMonthCalls, "today/last_7d never touch the ported total's monthly buckets");
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_description_renders_the_ported_total_not_the_unique_count(): void
    {
        // DEV-2651 t2 pin 2: toStat()->getDescription() must render the PORTED total
        // series, never the unique-plate series the headline already shows. Distinct
        // sentinels for each so a description reading the wrong series fails loudly
        // rather than silently passing.
        $this->actingAsProUser();

        $widget = new CountingUniqueScanCounts;
        $widget->date = 'this_month';
        $widget->rangeRollupResult = ['totals' => [0 => 999], 'names' => []];
        $widget->totalCurrentMonthResult = 321;

        $description = (string) $widget->toStat()->getDescription();

        $this->assertSame(number_format(321).' total scans', $description);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_data_returns_two_datasets_and_get_options_carries_no_second_axis(): void
    {
        // DEV-2651 t2 pin 3: AC-3's two grouped datasets over 13 shared labels, and AC-4's
        // explicit single-axis exception -- asserted NEGATIVELY so a copy-paste from a
        // ratio card's scales.y1 cannot slip in unnoticed.
        $this->actingAsProUser();

        $widget = new CountingUniqueScanCounts;
        $widget->date = 'this_month';

        $data = $widget->getData();

        $this->assertCount(2, $data['datasets'], 'AC-3: exactly two datasets, unique + ported total');
        $this->assertCount(13, $data['labels']);
        $this->assertCount(13, $data['datasets'][0]['data']);
        $this->assertCount(13, $data['datasets'][1]['data']);

        $options = (new ReflectionMethod($widget, 'getOptions'))->invoke($widget);

        $this->assertArrayNotHasKey(
            'y1',
            $options['scales'] ?? [],
            "AC-4: this is not a ratio card -- both series share ONE axis, unlike a ratio card's scales.y1"
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_total_series_month_keys_are_resolved_with_the_bucket_zone_not_the_raw_zone(): void
    {
        // DEV-2651 t2 pin 4: the ported total leg must bucket by bucketZone(), exactly
        // like the unique series -- otherwise the two datasets on the two-series chart
        // would disagree about which month is "current" for a viewer on an unlisted zone.
        Carbon::setTestNow('2026-08-31 23:00:00');

        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => '+05:00']);
        $user->save();
        $this->actingAs($user->fresh());

        $widget = new RecordingUniqueScanCounts;
        (new ReflectionMethod($widget, 'getTotalMonthlyData'))->invoke($widget, false);

        $this->assertNotEmpty($widget->totalMonthlySeriesCalls, 'the past months must be filled');
        $this->assertNotEmpty($widget->totalCurrentMonthCalls, 'the current month must be filled');

        $bucketZone = ScanPlateStatsBatchQuery::safeTimezone($user->fresh()->timezone);

        foreach (array_merge($widget->totalMonthlySeriesCalls, $widget->totalCurrentMonthCalls) as $call) {
            $this->assertSame(
                $bucketZone,
                $call['timezone'],
                'the ported total leg must bucket by the SAME zone as the unique series'
            );
        }

        // Not vacuous: the raw zone genuinely differs from the bucket zone for this fixture.
        $this->assertNotSame($bucketZone, $user->fresh()->timezone);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_card_total_routes_today_and_last_7d_through_the_range_rollup(): void
    {
        $this->actingAsProUser();

        $widget = new CountingUniqueScanCounts;
        $widget->rangeRollupResult = ['totals' => [0 => 42], 'names' => []];
        $widget->date = 'today';

        $this->assertSame(42, $widget->getCardTotal());
        $this->assertSame(1, $widget->rangeRollupCalls);
        $this->assertSame(0, $widget->monthlySeriesCalls);

        $widget2 = new CountingUniqueScanCounts;
        $widget2->rangeRollupResult = ['totals' => [0 => 77], 'names' => []];
        $widget2->date = 'last_7d';

        $this->assertSame(77, $widget2->getCardTotal());
        $this->assertSame(1, $widget2->rangeRollupCalls);
        $this->assertSame(0, $widget2->monthlySeriesCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_card_total_routes_this_month_and_last_month_through_the_monthly_buckets(): void
    {
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';

        $this->seedMonthlyCacheBuckets($user->org_id, $timezone);

        $thisMonthWidget = new CountingUniqueScanCounts;
        $thisMonthWidget->date = 'this_month';
        $this->assertSame(555, $thisMonthWidget->getCardTotal());
        $this->assertSame(0, $thisMonthWidget->monthlySeriesCalls);
        $this->assertSame(0, $thisMonthWidget->rangeRollupCalls);

        $lastMonthWidget = new CountingUniqueScanCounts;
        $lastMonthWidget->date = 'last_month';
        $this->assertSame(444, $lastMonthWidget->getCardTotal());
        $this->assertSame(0, $lastMonthWidget->monthlySeriesCalls);
        $this->assertSame(0, $lastMonthWidget->rangeRollupCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_shared_range_cache_key_is_class_neutral(): void
    {
        $key = ScanPlateStatsBatchQuery::sharedRangeCacheKey(1, '2026-08-01 00:00:00', '2026-08-31 23:59:59', 'America/Chicago');

        $this->assertStringNotContainsStringIgnoringCase(
            'UniqueScanCounts',
            $key,
            'the shared window-rollup key must be class-neutral so a sibling card can read it'
        );
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_fetch_range_rollup_reads_and_writes_the_shared_range_cache_key(): void
    {
        // S9: CountingUniqueScanCounts overrides fetchRangeRollup() wholesale for every
        // OTHER test in this file, so the real cache()->remember(sharedRangeCacheKey())
        // body has no coverage anywhere else. This drives the REAL (non-overridden)
        // method on a plain UniqueScanCounts instance.
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';

        $start = '2026-01-01 00:00:00';
        $end = '2026-01-01 23:59:59';

        $sharedKey = ScanPlateStatsBatchQuery::sharedRangeCacheKey($user->org_id, $start, $end, $timezone);

        // A sentinel a real clarity.scans_cold query for this org/window could never
        // produce -- if fetchRangeRollup() read anywhere OTHER than sharedRangeCacheKey(),
        // the real (near-certainly zero, or an outright query failure) result would fail
        // this assertion instead of silently passing. `{totals, names}` is the DEV-2468
        // shape the `-v2` key bump exists to make the only reachable one.
        $sentinel = ['totals' => [0 => 999999], 'names' => []];
        cache()->put($sharedKey, $sentinel, now()->addMinute());

        $widget = new UniqueScanCounts;
        $method = new ReflectionMethod($widget, 'fetchRangeRollup');

        $result = $method->invoke($widget, $user->org_id, $start, $end, $timezone);
        $this->assertSame($sentinel, $result, 'fetchRangeRollup() must read under ScanPlateStatsBatchQuery::sharedRangeCacheKey()');

        // A second read of the exact same org+window+timezone must not re-query -- it
        // hits the same cache key cache()->remember() already populated (the DEV-2468
        // sharing seam).
        $second = $method->invoke($widget, $user->org_id, $start, $end, $timezone);
        $this->assertSame($sentinel, $second);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_clickhouse_failure_while_filling_past_months_does_not_cache_a_fabricated_zero(): void
    {
        // S1: the historical bug -- catching the Throwable and falling through to the
        // write loop persisted a fabricated 0 at getCacheTTL(1) (up to a year) for every
        // uncached month, with no self-heal (the refill only re-queries null buckets, and
        // 0 is not null). This pins the corrected contract: on a genuine failure, NO past
        // month bucket is written at all, and a short-lived failure marker suppresses an
        // immediate retry rather than hammering a hard-down ClickHouse.
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';

        // Short-circuits resolveCurrentMonthFromCold() so this test exercises ONLY the
        // past-months failure path, not a real ClickHouse round trip for the open month.
        $bucketZone = ScanPlateStatsBatchQuery::safeTimezone($timezone);
        cache()->put($this->cacheKeyWithTz(
            key: now(tz: $bucketZone)->format('Y-m'),
            orgId: $user->org_id,
            timezone: $bucketZone,
        ), 0);

        $widget = new ThrowingUniqueScanCounts;
        (new ReflectionMethod($widget, 'getMonthlyData'))->invoke($widget, false);

        $this->assertSame(1, $widget->monthlySeriesCalls);

        $someMonthKey = now(tz: $timezone)->startOfMonth()->subMonths(6)->format('Y-m');
        $this->assertNull(
            cache()->get($this->cacheKeyWithTz(key: $someMonthKey, orgId: $user->org_id, timezone: $timezone)),
            'a failed batch must not write ANY past-month bucket, fabricated-zero or otherwise'
        );

        // A second render (fresh instance, same request-cycle shape) must not re-query --
        // the failure marker is set and must suppress the retry within its TTL.
        cache()->put($this->cacheKeyWithTz(
            key: now(tz: $bucketZone)->format('Y-m'),
            orgId: $user->org_id,
            timezone: $bucketZone,
        ), 0);

        $widget2 = new ThrowingUniqueScanCounts;
        (new ReflectionMethod($widget2, 'getMonthlyData'))->invoke($widget2, false);
        $this->assertSame(0, $widget2->monthlySeriesCalls, 'the failure marker must suppress a retry within its TTL');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_past_month_puts_follow_the_settle_window_per_month(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');

        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => 'UTC']);
        $user->save();
        $this->actingAs($user->fresh());

        $written = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
            $written[$event->key] = $event->seconds;
        });

        $widget = new RecordingUniqueScanCounts;
        (new ReflectionMethod($widget, 'getMonthlyData'))->invoke($widget, false);

        $orgId = $user->org_id;
        $openKey = ScanPlateStatsBatchQuery::sharedMonthCacheKey($orgId, '2026-08', 'UTC');
        $recentKey = ScanPlateStatsBatchQuery::sharedMonthCacheKey($orgId, '2026-07', 'UTC');
        $settledKey = ScanPlateStatsBatchQuery::sharedMonthCacheKey($orgId, '2026-06', 'UTC');

        $this->assertArrayHasKey($openKey, $written);
        $this->assertArrayHasKey($recentKey, $written);
        $this->assertArrayHasKey($settledKey, $written);
        $this->assertSame(600, $written[$openKey]);
        $this->assertSame(600, $written[$recentKey]);
        $this->assertSame(now()->addYear()->getTimestamp() - now()->getTimestamp(), $written[$settledKey]);
        $this->assertStringNotContainsString('UniqueScanCounts', $openKey);
        $this->assertStringNotContainsString('this_month', $openKey);
        $this->assertSame(8 * 86400, DashboardWidget::CLOSED_MONTH_SETTLE_FULL_SECONDS);
    }

    /**
     * Review-2 H1. The SAME contract as the test above, for the PORTED TOTAL leg -- which
     * shipped without it: it caught the Throwable, set $batchResult = [] and fell through to
     * the write loop, persisting a fabricated 0 for all twelve past months at a one-year TTL
     * with no marker to retry behind. Recoverable only by cache:clear.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_a_clickhouse_failure_while_filling_the_ported_total_months_does_not_cache_a_fabricated_zero(): void
    {
        $user = $this->actingAsProUser();
        $timezone = $user->timezone ?? 'UTC';

        $widget = new ThrowingTotalUniqueScanCounts;
        (new ReflectionMethod($widget, 'resolveTotalPastMonthsFromAggregate'))
            ->invoke($widget, $user->org_id, $timezone);

        $this->assertSame(1, $widget->totalSeriesCalls);

        $someMonthKey = now(tz: $timezone)->startOfMonth()->subMonths(6)->format('Y-m');
        $this->assertNull(
            cache()->get('unique-scan-counts:scan-total:'.$someMonthKey.':org='.$user->org_id.':tz='.$timezone),
            'a failed total batch must not write ANY past-month bucket, fabricated-zero or otherwise'
        );

        $widget2 = new ThrowingTotalUniqueScanCounts;
        (new ReflectionMethod($widget2, 'resolveTotalPastMonthsFromAggregate'))
            ->invoke($widget2, $user->org_id, $timezone);
        $this->assertSame(0, $widget2->totalSeriesCalls, 'the total leg needs its OWN failure marker to suppress the retry');
    }

    /**
     * Agent-QA finding. The three ratio cards each label their ratio series independently, and
     * nothing compared them — so RecoveryCounts shipped 'Unique Scans to Repo' (the title of the
     * card t8 DELETED) while its siblings said 'Scans per Case Claim' / 'Scans per LPR Repo', and
     * while its own description line said "N scans per repo". Three namings for one concept.
     *
     * This is a cross-card invariant, so it is asserted across all three at once rather than
     * per-card — a per-card assertion is exactly what let them drift apart.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_three_ratio_cards_label_their_ratio_series_consistently(): void
    {
        foreach ([
            UniqueLprCaseClaims::class,
            LprRepossessions::class,
            RecoveryCounts::class,
        ] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            // Strip comments/docblocks so this checks RENDERED copy, not prose about the history.
            $code = preg_replace('#/\*.*?\*/#s', '', $source);
            $code = preg_replace('#//[^\n]*#', '', (string) $code);

            preg_match_all("/'((?:[^']*[Ss]cans per [^']*)|(?:[^']*Scans to [^']*))'/", (string) $code, $m);

            foreach ($m[1] as $label) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'unique',
                    $label,
                    "{$class}: the ratio label \"{$label}\" says 'unique' — its siblings do not, and its own description line does not"
                );
                $this->assertStringNotContainsString(
                    'Scans to',
                    $label,
                    "{$class}: \"{$label}\" names the deleted card rather than describing the ratio"
                );
            }
        }
    }

    /**
     * Review-4 R4-E. Review-3 stopped a failed total being CACHED as zero for a year; this stops
     * it being DISPLAYED as though it were real. The read loop's `?? 0` otherwise renders twelve
     * confident zero bars and "0 total scans" beside a healthy unique-plate series -- a raw total
     * below a distinct-plate count, which cannot happen.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_an_unavailable_total_renders_as_unavailable_not_as_zero(): void
    {
        $user = $this->actingAsProUser();

        $widget = new ThrowingTotalUniqueScanCounts;
        $widget->date = 'last_month';
        $widget->expanded = true;

        $this->assertSame(
            UniqueScanCounts::UNAVAILABLE_VALUE.' total scans',
            (string) $widget->toStat()->getDescription(),
            'a failed total must not render as a confident 0'
        );

        $data = $widget->getData();
        $this->assertCount(1, $data['datasets'], 'the Total Scans series must be dropped, not plotted as twelve zeros');
        $this->assertSame('Scan Counts', $data['datasets'][0]['label']);
    }

    /** Review-4 R4-E: and the flag must not stick past a filter switch (review-1 M1's lesson). */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_refresh_data_resets_the_total_unavailable_flag(): void
    {
        $this->actingAsProUser();

        $widget = new ThrowingTotalUniqueScanCounts;
        $widget->date = 'last_month';

        $this->assertSame(UniqueScanCounts::UNAVAILABLE_VALUE.' total scans', (string) $widget->toStat()->getDescription());

        $flag = new \ReflectionProperty(UniqueScanCounts::class, 'totalUnavailable');
        $flag->setAccessible(true);
        $this->assertTrue($flag->getValue($widget));

        $widget->refreshData('this_month');

        $this->assertFalse($flag->getValue($widget), 'a total failure must not stick past a refresh-dashboard event');
    }

    /**
     * Review-3 H1/H2. The test above stubs the SEAM, so it proves the guard fires when
     * something reaches it -- and it passed while the guard was completely inert, because the
     * real seam called ClickhouseQuery::query(), which catches Throwable and returns [] in
     * production|testing. An empty array is not an exception, so the guard never ran and
     * twelve fabricated zeros were written at a one-year TTL anyway.
     *
     * A seam-stubbing test can never catch that. This pins the missing half directly: the real
     * seam must use a NON-swallowing entry point, so a failure actually arrives as a Throwable.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_total_seam_uses_a_non_swallowing_entry_point(): void
    {
        $source = file_get_contents((new \ReflectionClass(UniqueScanCounts::class))->getFileName());
        $body = substr($source, strpos($source, 'function fetchTotalMonthlySeries'));
        $body = substr($body, 0, strpos($body, "\n    }"));

        $this->assertStringContainsString('ScanStatsBatchQuery::handle(', $body);
        $this->assertStringNotContainsString(
            'ScanStatsBatchQuery::query(',
            $body,
            'ClickhouseQuery::query() swallows Throwable to [] in production|testing, which makes '
            .'resolveTotalPastMonthsFromAggregate()\'s fabricated-zero guard dead code'
        );
    }

    /**
     * Review-3 H2. The bounds are minted in bucketZone() but Scan::scopeBetween() defaults to
     * the RAW viewer zone, so the zone must be threaded explicitly. For a viewer whose
     * users.dti zone is not a valid IANA identifier the two differ, and the current-month bar
     * shifts by the full UTC offset away from the distinct-plate bar beside it.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_current_month_total_threads_its_bucket_zone_into_between(): void
    {
        $source = file_get_contents((new \ReflectionClass(UniqueScanCounts::class))->getFileName());
        $body = substr($source, strpos($source, 'function fetchTotalCurrentMonthCount'));
        $body = substr($body, 0, strpos($body, "\n    }"));

        $this->assertStringContainsString('->between($start, $end, $timezone)', $body);
    }

    /**
     * Review-2 M2. The ratio series must be visually separable from the count series, or the
     * legend AC-3 requires distinguishes nothing. The first fix used a grey that collided with
     * makeDataset()'s own grey fallbacks, so this asserts separation from EVERY colour
     * makeDataset() can produce, not just from the accent.
     *
     * Human QA folded RecoveryCounts in: it carried its own hardcoded orange, so all three
     * ratio cards now draw the one constant on the shared trait and all three are covered.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_the_ratio_series_colour_collides_with_no_colour_make_dataset_can_produce(): void
    {
        $makeDatasetGreys = ['rgba(156, 163, 175, 0.3)', 'rgba(156, 163, 175, 0.5)', 'rgba(156, 163, 175, 0.6)', 'rgba(156, 163, 175, 0.8)'];

        foreach ([UniqueLprCaseClaims::class, LprRepossessions::class, RecoveryCounts::class] as $class) {
            $colour = (new \ReflectionClass($class))->getConstant('RATIO_SERIES_COLOUR');
            $accent = 'rgba('.\implode(', ', (new $class)->getRgbColor()).', ';

            $this->assertIsString($colour, "{$class}: the shared ratio colour constant must resolve");
            $this->assertNotContains($colour, $makeDatasetGreys, "{$class}: the ratio colour must not reuse a makeDataset() grey");
            $this->assertStringNotContainsString($accent, $colour, "{$class}: the ratio colour must not reuse the card's accent");
        }
    }

    private function actingAsProUser(): User
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeUserPro($user);
        $this->actingAs($user);

        return $user;
    }

    /**
     * DEV-2468: the monthly buckets moved off DashboardWidget::buildWidgetCacheKey() (the
     * class-basename-prefixed StatHelper::cacheKey() shape) onto
     * ScanPlateStatsBatchQuery::sharedMonthCacheKey() -- class-neutral by construction, so
     * this helper no longer takes a widget class.
     */
    private function cacheKeyWithTz(string $key, int $orgId, string $timezone): string
    {
        return ScanPlateStatsBatchQuery::sharedMonthCacheKey($orgId, $key, $timezone);
    }

    public function test_a_positive_offset_zone_never_requests_a_month_window_ending_in_the_future(): void
    {
        // The bug this pins: the widget used to mint month keys and the batch window with the
        // viewer's RAW zone while handle() cut and grouped with the sanitised one. For a zone
        // ClickHouse cannot parse and that is EAST of UTC, the viewer's local month rolls over
        // first -- so the widget asked for a "past" month whose end bound had not happened yet
        // in UTC, then cached the short count at a one-year TTL with no self-heal.
        //
        // Reproduced exactly as review did: 2026-08-31 23:00 UTC is already 2026-09-01 04:00
        // at +05:00, so local September has begun while UTC is still in August.
        Carbon::setTestNow('2026-08-31 23:00:00');

        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => '+05:00']);
        $user->save();
        $this->actingAs($user->fresh());

        $this->assertSame('+05:00', auth()->user()->timezone, 'the fixture must actually be on the unlisted zone');

        $widget = new RecordingUniqueScanCounts;
        $method = new ReflectionMethod($widget, 'getMonthlyData');
        $method->invoke($widget, false);

        $this->assertNotEmpty($widget->monthlySeriesCalls, 'a cold render must fill the past months');

        foreach ($widget->monthlySeriesCalls as $call) {
            // Interpret the bound the way handle() ACTUALLY will -- it re-sanitises whatever
            // zone it is handed, so reading the naive string back in the widget's own zone
            // would hide the very mismatch this test exists to catch (verified: doing that
            // makes this test pass against the defect).
            $queryZone = ScanPlateStatsBatchQuery::safeTimezone($call['timezone']);
            $endUtc = Carbon::parse($call['end'], $queryZone)->utc();

            $this->assertTrue(
                $endUtc->lessThanOrEqualTo(now()),
                sprintf(
                    'batch window ends %s UTC, which is %d minutes in the FUTURE (bound "%s" read in "%s")',
                    $endUtc->toDateTimeString(),
                    abs($endUtc->diffInMinutes(now())),
                    $call['end'],
                    $queryZone
                )
            );
        }
    }

    public function test_bar_labels_and_bucket_keys_are_cut_with_the_same_zone(): void
    {
        // DashboardWidget::getData() zips labels to data POSITIONALLY and generateColors()
        // matches the highlight against the label STRING, so a one-month skew between the
        // label zone and the bucket zone mislabels all 13 bars instead of failing. An earlier
        // revision bucketed with the sanitised zone while inheriting both label methods on
        // the raw one. Listed zones were unaffected, which is why it survived two rounds.
        //
        // 2026-08-31 23:00 UTC is already 2026-09-01 at +05:00, so raw and sanitised
        // disagree about which month is current -- exactly the case that broke.
        Carbon::setTestNow('2026-08-31 23:00:00');

        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => '+05:00']);
        $user->save();
        $this->actingAs($user->fresh());

        $widget = new RecordingUniqueScanCounts;

        $labels = $widget->getGraphLabels();
        $newestLabel = end($labels);

        // The label the card prints for the newest bar must name the month whose bucket key
        // the data actually occupies.
        $bucketZone = ScanPlateStatsBatchQuery::safeTimezone($user->fresh()->timezone);
        $newestBucket = Carbon::now($bucketZone)->startOfMonth();

        $this->assertSame(
            $newestBucket->format('M \'y'),
            $newestLabel,
            'the newest bar label must name the month the newest bucket holds'
        );

        // And the highlight, which is matched against those same label strings.
        $this->assertContains(
            $newestBucket->format('M \'y'),
            $labels,
            'the highlighted month must exist among the labels, or generateColors() colours nothing'
        );
    }

    public function test_bar_thirteen_is_resolved_with_the_bucket_zone_not_the_raw_zone(): void
    {
        // Bars 1-12 come from handle() (bucket zone) and bar 13 from handleRange() via
        // resolveCurrentMonthFromCold(). handleRange() takes its zone VERBATIM, so if the
        // widget hands it the raw zone the final bar is cut on a different convention from
        // the twelve beside it. Review found this invariant had no barrier at all -- the
        // recording scaffolding existed but nothing read it.
        Carbon::setTestNow('2026-08-31 23:00:00');

        $user = $this->actingAsProUser();
        $user->dti = array_merge($user->dti ?: [], ['timeZone' => '+05:00']);
        $user->save();
        $this->actingAs($user->fresh());

        $widget = new RecordingUniqueScanCounts;
        (new ReflectionMethod($widget, 'getMonthlyData'))->invoke($widget, false);

        $this->assertNotEmpty($widget->rangeRollupCalls, 'bar 13 must resolve through the range rollup');

        $expected = ScanPlateStatsBatchQuery::safeTimezone($user->fresh()->timezone);

        foreach ($widget->rangeRollupCalls as $call) {
            $this->assertSame(
                $expected,
                $call['timezone'],
                'resolveCurrentMonthFromCold() must pass the BUCKET zone so bar 13 aligns with bars 1-12'
            );
        }
    }

    private function seedMonthlyCacheBuckets(int $orgId, string $timezone): void
    {
        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $dateType = $targetMonth->format('Y-m');
            $value = match ($i) {
                0 => 555,
                1 => 444,
                default => $i,
            };

            cache()->put(
                $this->cacheKeyWithTz(key: $dateType, orgId: $orgId, timezone: $timezone),
                $value,
                now()->addYear()
            );
        }
    }
}

class RecordingUniqueScanCounts extends UniqueScanCounts
{
    /** @var array<int, array{start: string, end: string, timezone: string}> */
    public array $monthlySeriesCalls = [];

    /** @var array<int, array{start: string, end: string, timezone: string}> */
    public array $rangeRollupCalls = [];

    /** @var array<int, array{start: string, end: string, timezone: string}> */
    public array $totalMonthlySeriesCalls = [];

    /** @var array<int, array{start: string, end: string, timezone: string}> */
    public array $totalCurrentMonthCalls = [];

    protected function fetchMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->monthlySeriesCalls[] = compact('start', 'end', 'timezone');

        return [];
    }

    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeRollupCalls[] = compact('start', 'end', 'timezone');

        return ['totals' => [0 => 0], 'names' => []];
    }

    protected function fetchTotalMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->totalMonthlySeriesCalls[] = compact('start', 'end', 'timezone');

        return [];
    }

    protected function fetchTotalCurrentMonthCount(int $orgId, string $start, string $end, string $timezone): int
    {
        $this->totalCurrentMonthCalls[] = compact('start', 'end', 'timezone');

        return 0;
    }
}

class CountingUniqueScanCounts extends UniqueScanCounts
{
    public int $monthlySeriesCalls = 0;

    public int $rangeRollupCalls = 0;

    public int $totalMonthlySeriesCalls = 0;

    public int $totalCurrentMonthCalls = 0;

    public int $totalRangeTotalCalls = 0;

    /** @var array{totals: array<int, int>, names: array<int, string>} */
    public array $rangeRollupResult = ['totals' => [0 => 0], 'names' => []];

    public int $totalCurrentMonthResult = 0;

    public int $totalRangeTotalResult = 0;

    protected function fetchMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->monthlySeriesCalls++;

        return [];
    }

    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->rangeRollupCalls++;

        return $this->rangeRollupResult;
    }

    protected function fetchTotalMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->totalMonthlySeriesCalls++;

        return [];
    }

    protected function fetchTotalCurrentMonthCount(int $orgId, string $start, string $end, string $timezone): int
    {
        $this->totalCurrentMonthCalls++;

        return $this->totalCurrentMonthResult;
    }

    protected function fetchTotalRangeTotal(int $orgId, string $start, string $end): int
    {
        $this->totalRangeTotalCalls++;

        return $this->totalRangeTotalResult;
    }
}

class ThrowingUniqueScanCounts extends UniqueScanCounts
{
    public int $monthlySeriesCalls = 0;

    protected function fetchMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->monthlySeriesCalls++;

        throw new \RuntimeException('simulated ClickHouse outage');
    }
}

/** Review-2 H1: fails the PORTED TOTAL leg's batch, the sibling of ThrowingUniqueScanCounts. */
class ThrowingTotalUniqueScanCounts extends UniqueScanCounts
{
    public int $totalSeriesCalls = 0;

    protected function fetchTotalMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->totalSeriesCalls++;

        throw new \RuntimeException('simulated ClickHouse outage (scan_report_hourly)');
    }

    /**
     * The current-month leg SUCCEEDS. resolveTotalCurrentMonthFromHot() is deliberately
     * catch-free, so a throw here would propagate and 500 the grid — a different failure mode
     * entirely. The realistic shape is the expensive 13-month batch timing out against the 20s
     * cap while the cheap single-month query returns, and that is the shape that renders zeros.
     */
    protected function fetchTotalCurrentMonthCount(int $orgId, string $start, string $end, string $timezone): int
    {
        return 777;
    }

    /**
     * Review-4 R4-E: the UNIQUE legs stay HEALTHY while the total fails. That asymmetry is the
     * whole point — it is the only shape that produces the structurally impossible reading, a
     * raw total rendering below a distinct-plate count on the same bar.
     *
     * @return array<string, int>
     */
    protected function fetchMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        return [now(tz: $timezone)->startOfMonth()->subMonth()->format('Y-m') => 4_242];
    }

    /** @return array{totals: array<int, int>, names: array<int, string>} */
    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        return ['totals' => [1_111], 'names' => []];
    }
}

class OverviewWithCountingUniqueScanCounts extends CompanyMetricsOverview
{
    public ?CountingUniqueScanCounts $capturedWidget = null;

    public static function cards(): array
    {
        return ['unique-scan-counts' => CountingUniqueScanCounts::class];
    }

    protected function makeMetricWidget(string $class): DashboardMetricCard
    {
        $widget = parent::makeMetricWidget($class);

        if ($widget instanceof CountingUniqueScanCounts) {
            $this->capturedWidget = $widget;
        }

        return $widget;
    }
}
