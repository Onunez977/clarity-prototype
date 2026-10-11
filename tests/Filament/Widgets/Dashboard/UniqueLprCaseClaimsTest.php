<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\UniqueLprCaseClaims;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentColor;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DEV-2463 t2. Uses a counting subclass (StagingLiveHitLeaderTest's technique) rather
 * than DB::listen() -- the ClickHouse driver never fires query listeners, so a
 * listener-based assertion would silently prove nothing.
 */
class UniqueLprCaseClaimsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_get_card_key(): void
    {
        $widget = new UniqueLprCaseClaims;

        $this->assertSame('unique-lpr-case-claims', $widget->getCardKey());
        $this->assertSame('LPR Case Claims', $widget->getCardLabel());
        $this->assertSame('LPR Case Claims', $widget->getDatasetLabel());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_stat_color_is_explicit_and_not_gray(): void
    {
        $color = (new UniqueLprCaseClaims)->getStatColor();

        $this->assertNotSame('gray', $color, 'default => gray would render identically to the locked state');
        $this->assertNotSame('', $color);

        // An unregistered colour is non-gray but has no themed shades, so the card renders
        // untinted with no error -- the exact trap 'purple' fell into during implementation.
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
    #[Group('unique-lpr-case-claims')]
    public function test_can_view_card_data_needs_no_entitlement(): void
    {
        // Guest: the default short-circuits to true (the call must be TOTAL, not merely
        // permissive -- see DashboardCardVisibilityTest).
        $this->assertTrue(auth()->guest());
        $this->assertTrue(UniqueLprCaseClaims::canViewCardData());

        // A plain ADMIN on a BASIC org -- the case that used to be denied.
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);
        $this->assertTrue(UniqueLprCaseClaims::canViewCardData());

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

        $this->assertTrue(UniqueLprCaseClaims::canViewCardData());

        // And an impersonator session changes nothing, because there is nothing to bypass.
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);
        $this->assertTrue(UniqueLprCaseClaims::canViewCardData());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_locked_stat_renders_the_placeholder_and_executes_no_query(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new CountingUniqueLprCaseClaims;
        $stat = $widget->lockedStat();

        $this->assertSame(DashboardMetricCard::LOCKED_VALUE, $stat->getValue());
        $this->assertNull($stat->getChart());
        $this->assertSame(0, $widget->monthlySeriesCalls, 'lockedStat() must never touch the monthly series');
        $this->assertSame(0, $widget->windowCalls, 'lockedStat() must never touch the window query');
    }

    /**
     * AC-4's real proof: EXACTLY one round trip per seam, for all four filter states.
     * getMonthlyData() (the sparkline, always resolved by chartSeries()) must be ONE
     * call regardless of $date; getModelQueryForDates() (today/last_7d only, via
     * currentPeriodTotal()) must fire ONLY for those two states -- never for
     * this_month/last_month, which read the already-fetched monthly series instead.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
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
            // The monthly-series cache key is date-filter-independent (it always holds
            // the full 13-month series), so each filter state is isolated by flushing
            // between iterations -- otherwise only the FIRST iteration would ever see a
            // real call, which would prove nothing about the other three.
            cache()->flush();

            $widget = new CountingUniqueLprCaseClaims;
            $widget->date = $date;

            $widget->toStat();

            $this->assertSame(1, $widget->monthlySeriesCalls, "{$date}: the 13-month series must be ONE batched call, never a fan-out");
            $this->assertSame(
                $expectsWindowCall ? 1 : 0,
                $widget->windowCalls,
                "{$date}: the single-window query must fire only for today/last_7d"
            );

            // A second resolve on the same instance adds no further trips.
            $widget->toStat();
            $this->assertSame(1, $widget->monthlySeriesCalls, "{$date}: the per-instance memo must hold");
            $this->assertSame($expectsWindowCall ? 1 : 0, $widget->windowCalls, "{$date}: the per-instance memo must hold");

            // A FRESH instance for the same state must not re-query either -- both seams
            // are cache-backed (Cache::flexible() / cache()->remember()), not per-instance.
            $freshWidget = new CountingUniqueLprCaseClaims;
            $freshWidget->date = $date;
            $freshWidget->toStat();

            $this->assertSame(0, $freshWidget->monthlySeriesCalls, "{$date}: a fresh instance must read the already-warm cache");
            $this->assertSame(0, $freshWidget->windowCalls, "{$date}: a fresh instance must read the already-warm cache");
        }
    }

    /**
     * The load-bearing risk: this card's monthly buckets are UTC-anchored, NOT the
     * viewer's timezone. Frozen at a UTC instant that is still the PREVIOUS calendar day
     * in America/Chicago, so a user-tz implementation would request the wrong bucket key
     * entirely and silently read 0 instead of the seeded value.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_month_buckets_align_on_utc_not_the_viewers_timezone(): void
    {
        // 2026-08-01 02:00 UTC is still 2026-07-31 21:00 in America/Chicago.
        Carbon::setTestNow('2026-08-01 02:00:00');

        // No 'dti' set, so User::timezone() falls back to 'America/Chicago' -- already
        // non-UTC, which is exactly the trap this test proves the card avoids.
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->assertSame('America/Chicago', $user->timezone);
        $this->actingAs($user);

        $stub = new StubbingUniqueLprCaseClaims;
        $stub->series = [
            '2026-08-01' => ['all_claims' => 5, 'successful' => 5, 'deduped' => 777],
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

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_months_with_no_data_render_zero_not_missing(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $stub = new StubbingUniqueLprCaseClaims;
        $stub->series = [];

        $method = new ReflectionMethod($stub, 'getMonthlyData');
        $method->setAccessible(true);
        $data = $method->invoke($stub, false);

        $this->assertCount(13, $data);
        $this->assertSame(array_fill(0, 13, 0), $data);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_a_clickhouse_failure_does_not_render_as_zero(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new ThrowingUniqueLprCaseClaims;
        $widget->date = 'today';

        $this->expectException(\RuntimeException::class);

        $widget->toStat();
    }

    /**
     * DEV-2651 t3, AC-2. The Stat's secondary description line -- unique scans divided by
     * the SAME deduped bucket the headline renders (decision 9's zip, exercised end to end).
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_stat_description_reports_the_scans_per_case_claim_ratio(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RatioStubbingUniqueLprCaseClaims;
        $widget->claimsSeries = ['2026-08-01' => ['all_claims' => 10, 'successful' => 9, 'deduped' => 8]];
        $widget->scansBatch = ['2026-08' => 40];

        $description = (string) $widget->toStat()->getDescription();

        $this->assertSame('5.0 scans per case claim', $description);
    }

    /**
     * DEV-2651 t3, AC-3/AC-4 and decision 9. The expanded chart's two datasets share the
     * same 13 labels; the ratio dataset must be the numerator zipped against the EXACT
     * deduped bucket the count dataset renders for that same index, not a second,
     * independently-bucketed denominator.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_get_data_zips_the_ratio_dataset_onto_the_second_axis_over_the_same_labels(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RatioStubbingUniqueLprCaseClaims;
        $widget->claimsSeries = ['2026-08-01' => ['all_claims' => 10, 'successful' => 9, 'deduped' => 8]];
        $widget->scansBatch = ['2026-08' => 40];

        $data = $widget->getData();

        $this->assertCount(13, $data['labels']);
        $this->assertCount(2, $data['datasets'], 'the expanded chart must carry both the count and ratio series');
        $this->assertSame('y', $data['datasets'][0]['yAxisID']);
        $this->assertSame('y1', $data['datasets'][1]['yAxisID']);
        $this->assertCount(13, $data['datasets'][0]['data']);
        $this->assertCount(13, $data['datasets'][1]['data']);
        $this->assertSame(8, $data['datasets'][0]['data'][12], 'the count dataset must still be the deduped bucket');
        $this->assertSame(
            5.0,
            $data['datasets'][1]['data'][12],
            'the ratio dataset must divide by THAT SAME deduped bucket, not a second fetch'
        );
    }

    /**
     * DEV-2651 t3, decision 8. A thrown numerator degrades gracefully (this repo's app.env
     * is 'testing', RatioAgainstUniqueScans' own degrade branch): the headline is unaffected,
     * the description falls back to UNAVAILABLE_VALUE, and the ratio dataset is dropped
     * entirely rather than plotting a fabricated flat line.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_a_thrown_numerator_degrades_the_description_and_drops_the_ratio_dataset(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new ThrowingNumeratorUniqueLprCaseClaims;
        $widget->claimsSeries = ['2026-08-01' => ['all_claims' => 10, 'successful' => 9, 'deduped' => 8]];

        $stat = $widget->toStat();

        $this->assertSame('8', $stat->getValue(), 'the headline must still render the deduped claims total');
        $this->assertSame('-- scans per case claim', (string) $stat->getDescription());

        $data = $widget->getData();
        $this->assertCount(1, $data['datasets'], 'a numerator failure must drop the ratio dataset, not plot a fabricated one');
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
    #[Group('unique-lpr-case-claims')]
    public function test_the_count_series_is_one_colour_and_the_ratio_is_a_line(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new RatioStubbingUniqueLprCaseClaims;
        $widget->claimsSeries = ['2026-08-01' => ['all_claims' => 10, 'successful' => 9, 'deduped' => 8]];
        $widget->scansBatch = ['2026-08' => 40];

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
    #[Group('unique-lpr-case-claims')]
    public function test_a_degraded_numerator_keeps_the_per_month_highlight_on_the_lone_series(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new ThrowingNumeratorUniqueLprCaseClaims;
        $widget->claimsSeries = ['2026-08-01' => ['all_claims' => 10, 'successful' => 9, 'deduped' => 8]];

        $datasets = $widget->getData()['datasets'];

        $this->assertCount(1, $datasets);
        $this->assertIsArray($datasets[0]['backgroundColor'], 'the month highlight survives on a single-series chart');
    }

    /**
     * Review-4 R4-B. The dataset was already dropped (above) but getOptions() still emitted a
     * titled right-hand axis and a legend, so a degraded card rendered an axis with nothing
     * plotted against it. RecoveryCounts has always gated both; these two cards did not.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_a_degraded_numerator_drops_the_right_hand_axis_and_the_legend(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new ThrowingNumeratorUniqueLprCaseClaims;
        $widget->claimsSeries = ['2026-08-01' => ['all_claims' => 10, 'successful' => 9, 'deduped' => 8]];
        $widget->expanded = true;

        $method = new ReflectionMethod($widget, 'getOptions');
        $method->setAccessible(true);
        $options = $method->invoke($widget);

        $this->assertArrayNotHasKey('y1', $options['scales'], 'no axis for a series that is not drawn');
        $this->assertFalse($options['plugins']['legend']['display'], 'no legend for a single series');
        $this->assertNotEmpty($options['scales']['y']['title']['text'] ?? null, 'the count axis keeps its title');
    }

    /**
     * DEV-2651 t8. Folded forward from the deleted UniqueScansToCaseClaimsTest -- that class's
     * near-twin regression: overriding refreshData() DROPS the parent's
     * #[On('refresh-dashboard')] because PHP does not inherit method attributes onto an
     * override, silently deafening the EXPANDED card (keyed '<class>-expanded', so it is
     * never remounted) to the dashboard date toggle. Asserted through Livewire, since a direct
     * PHP call to refreshData() is exactly what would NOT catch a dropped registration.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_the_card_still_listens_for_the_dashboard_refresh_event(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        Livewire::test(CountingUniqueLprCaseClaims::class)
            ->assertSet('date', 'this_month')
            ->dispatch('refresh-dashboard', 'last_month')
            ->assertSet('date', 'last_month');
    }

    /**
     * DEV-2651 t8. Folded forward from the deleted UniqueScansToCaseClaimsTest.
     * $numeratorUnavailable (RatioAgainstUniqueScans) is a per-request flag with no reset of
     * its own -- refreshData() must clear it, or a numerator failure on one date filter renders
     * UNAVAILABLE_VALUE for every LATER filter on the same instance, indefinitely, including
     * after the underlying outage clears.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_refresh_data_resets_the_numerator_unavailable_flag(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new CountingUniqueLprCaseClaims;
        $widget->date = 'today';

        $property = new \ReflectionProperty(UniqueLprCaseClaims::class, 'numeratorUnavailable');
        $property->setAccessible(true);
        $property->setValue($widget, true);

        $this->assertSame(UniqueLprCaseClaims::UNAVAILABLE_VALUE.' scans per case claim', $widget->toStat()->getDescription());

        $widget->refreshData('last_7d');

        $this->assertFalse($property->getValue($widget), 'a numerator failure must not stick past a refresh-dashboard event');
        $this->assertNotSame(
            UniqueLprCaseClaims::UNAVAILABLE_VALUE.' scans per case claim',
            (string) $widget->toStat()->getDescription(),
            'the flag must not force UNAVAILABLE_VALUE for every later filter on the same instance'
        );
    }

    /**
     * DEV-2652 t2. 2026-08-01 02:00 UTC is still July in America/Chicago. The numerator
     * batch follows the viewer; getMonthlyData() stays on the UTC August bucket.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_ratio_numerator_uses_chicago_while_the_headline_stays_on_utc_august(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $this->assertSame('America/Chicago', $user->timezone);
        $this->assertSame('2026-08', now('UTC')->format('Y-m'), 'precondition: UTC is already August');
        $this->assertSame('2026-07', now(tz: $user->timezone)->format('Y-m'), 'precondition: the viewer is still in July');

        $widget = new RecordingNumeratorUniqueLprCaseClaims;
        $widget->claimsSeries = [
            '2026-08-01' => ['all_claims' => 5, 'successful' => 5, 'deduped' => 777],
        ];

        $this->invoke($widget, 'getRatioMonthlyData', []);

        $this->assertSame('America/Chicago', $widget->scansTimezone);
        $this->assertSame('2026-07', Carbon::parse((string) $widget->scansEnd)->format('Y-m'));

        $headline = $this->invoke($widget, 'getMonthlyData', [false]);
        $this->assertSame(777, $headline[12], 'the headline bucket stays the UTC August deduped figure');

        $cacheKey = ScanPlateStatsBatchQuery::sharedMonthCacheKey($user->org_id, '2026-07', 'America/Chicago');
        $this->assertStringContainsString(':tz=', $cacheKey);
        $this->assertSame(0, cache()->get($cacheKey));
    }

    /**
     * CST/PST/+05:00 are unlisted and become UTC. US/Central is kept when this PHP
     * can construct it and otherwise becomes America/Chicago. Either way
     * getRatioMonthlyData() must not throw.
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
    #[Group('unique-lpr-case-claims')]
    #[DataProvider('unsafeViewerZones')]
    public function test_ratio_numerator_records_the_sanitised_zone_for_an_unsafe_viewer_zone(string $zone, string $expected): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));

        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => $zone]],
            role: RoleEnum::ADMIN->value,
        );
        $this->actingAs($user);
        $this->assertSame($zone, $user->timezone);

        $widget = new RecordingNumeratorUniqueLprCaseClaims;

        $this->invoke($widget, 'getRatioMonthlyData', []);

        $this->assertSame($expected, $widget->scansTimezone);
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
 * empty results -- the assertions are about HOW MANY trips happen, not their content.
 */
class CountingUniqueLprCaseClaims extends UniqueLprCaseClaims
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

    // DEV-2651 t3. toStat() now also resolves the ratio -- stub RatioAgainstUniqueScans'
    // own seams so this round-trip test never reaches real (deliberately down) ClickHouse.
    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        return [];
    }

    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return ['totals' => [0], 'names' => []];
    }
}

/** Returns a caller-supplied series instead of hitting ClickHouse, for bucket-alignment assertions. */
class StubbingUniqueLprCaseClaims extends UniqueLprCaseClaims
{
    /** @var array<string, array{all_claims: int, successful: int, deduped: int}> */
    public array $series = [];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->series;
    }
}

/** Proves a failure propagates rather than rendering as a fabricated 0. */
class ThrowingUniqueLprCaseClaims extends UniqueLprCaseClaims
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

/** Stubs both legs so the ratio can be pinned without touching (deliberately down) ClickHouse. */
class RatioStubbingUniqueLprCaseClaims extends UniqueLprCaseClaims
{
    /** @var array<string, array{all_claims: int, successful: int, deduped: int}> */
    public array $claimsSeries = [];

    /** @var array<string, int> */
    public array $scansBatch = [];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->claimsSeries;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        return $this->scansBatch;
    }
}

/** Proves the numerator's own degrade path (never an uncaught exception in app.env=testing). */
class ThrowingNumeratorUniqueLprCaseClaims extends UniqueLprCaseClaims
{
    /** @var array<string, array{all_claims: int, successful: int, deduped: int}> */
    public array $claimsSeries = [];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->claimsSeries;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        throw new \RuntimeException('simulated ClickHouse failure');
    }
}

/** Records the zone and span that reach the unique-scans numerator. Never touches ClickHouse. */
class RecordingNumeratorUniqueLprCaseClaims extends UniqueLprCaseClaims
{
    /** @var array<string, array{all_claims: int, successful: int, deduped: int}> */
    public array $claimsSeries = [];

    public ?string $scansStart = null;

    public ?string $scansEnd = null;

    public ?string $scansTimezone = null;

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->claimsSeries;
    }

    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        $this->scansStart = $start;
        $this->scansEnd = $end;
        $this->scansTimezone = $timezone;

        return [];
    }

    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return ['totals' => [0], 'names' => []];
    }
}
