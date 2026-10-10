<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\LiveHits;
use App\Filament\Widgets\Dashboard\RecoveryLeader;
use App\Filament\Widgets\Dashboard\UniqueScanCounts;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Regression coverage for DEV-1927.
 *
 * Dashboard bar-graph month buckets were built with `now()->subMonths($i)->startOfMonth()`,
 * which overflows when "now" lands on a day the target month doesn't have (e.g. the 29th–31st):
 * subtracting from May 31 hits "Feb 31" → rolls to March, so February is skipped and March is
 * emitted twice. Normalizing with `startOfMonth()` *before* the subtraction fixes it.
 */
class DashboardMonthLabelsTest extends TestCase
{
    /**
     * Month-end anchors whose 13-month window crosses a short month, so the old
     * code would skip a month and duplicate the following one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function monthEndAnchors(): array
    {
        return [
            'May 31 skips Feb' => ['2026-05-31 12:00:00', "Feb '26"],
            'Mar 31 skips Feb' => ['2026-03-31 12:00:00', "Feb '26"],
            'Jul 31 skips Jun' => ['2026-07-31 12:00:00', "Jun '26"],
        ];
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[DataProvider('monthEndAnchors')]
    public function test_dashboard_graph_labels_have_no_duplicate_or_missing_months(string $anchor, string $expectedMonth): void
    {
        Carbon::setTestNow(Carbon::parse($anchor));

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        foreach ([RecoveryLeader::class, UniqueScanCounts::class] as $widgetClass) {
            $labels = (new $widgetClass)->getGraphLabels();

            $this->assertSame(
                \array_values(\array_unique($labels)),
                \array_values($labels),
                "{$widgetClass} produced duplicate month labels: ".\implode(', ', $labels),
            );

            $this->assertContains(
                $expectedMonth,
                $labels,
                "{$widgetClass} is missing the {$expectedMonth} bucket: ".\implode(', ', $labels),
            );
        }
    }

    /**
     * The data half of the AC: a value for a given month must render under that month's
     * label, not bleed into an adjacent bucket. Seeds a distinct sentinel per month into
     * the widget's cache (so getTotalMonthlyData reads cache only — no ClickHouse), then
     * zips labels<->data and asserts February's value sits under "Feb '26".
     *
     * DEV-2651 t8: repointed from the deleted ScanCount onto UniqueScanCounts's own ported
     * total series (getTotalMonthlyData()) -- the one series on this card still read through
     * DashboardWidget::buildWidgetCacheKey()'s class-basename-prefixed shape (getMonthlyData(),
     * the unique-plate series, moved that read onto ScanPlateStatsBatchQuery::sharedMonthCacheKey()
     * under DEV-2468 and is guarded separately in UniqueScanCountsTest). The seeded key must
     * therefore be UniqueScanCounts's OWN prefix ('unique-scan-counts', not 'scan-count'), under
     * its TOTAL_NAMESPACE ('scan-total:'), AND carry the ':tz=' segment
     * UniqueScanCounts::buildWidgetCacheKey() appends -- leaving the old bare
     * "scan-count:{dateType}:org={orgId}" string here would let this guard keep passing while
     * seeding a key nothing reads.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('scans')]
    public function test_unique_scan_counts_total_series_maps_each_months_data_to_its_own_bucket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $orgId = $user->org_id;
        $tz = $user->timezone ?? 'UTC';

        // Distinct, deterministic value per month so a mis-bucketing is detectable.
        $expectedByLabel = [];
        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $tz)->startOfMonth()->subMonths($i);
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');
            $value = (int) $targetMonth->format('Ym');
            cache()->put("unique-scan-counts:scan-total:{$dateType}:org={$orgId}:tz={$tz}", $value);
            $expectedByLabel[$targetMonth->format("M \'y")] = $value;
        }

        $widget = new UniqueScanCounts;
        $labels = $widget->getGraphLabels();

        $getTotalMonthlyData = new \ReflectionMethod($widget, 'getTotalMonthlyData');
        $getTotalMonthlyData->setAccessible(true);
        $data = $getTotalMonthlyData->invoke($widget, false);

        $byLabel = \array_combine($labels, $data);

        $this->assertSame(202602, $byLabel["Feb '26"], 'February data did not land in the Feb bucket.');
        $this->assertSame(202603, $byLabel["Mar '26"], 'March data did not land in the Mar bucket.');
        $this->assertSame($expectedByLabel, $byLabel, 'Each month must carry its own value.');
    }

    /**
     * DEV-2406 t3: the unique-plate series (SharedHitStatsData::getUniqueMonthlyData())
     * must go through the SAME 12..0 bucketing loop as the total series, not a parallel
     * one. Seeds a distinct sentinel per month into the `unique:` namespace only, so if a
     * future edit re-introduces an independent loop for the unique series it will
     * mis-bucket and this fails.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('hits')]
    public function test_live_hits_unique_series_maps_each_months_data_to_its_own_bucket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $orgId = $user->org_id;
        $tz = $user->timezone ?? 'UTC';

        // SharedHitStatsData::resolvePastUniqueMonths() gates on ALL FOUR hit-widget
        // prefixes being cached for a given month before it trusts the cache -- an
        // incompletely-seeded month is treated as uncached and triggers a live batch
        // query that overwrites every prefix. Seed all four so only "live-hits"'s
        // value is meaningfully asserted below.
        $expectedByLabel = [];
        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $tz)->startOfMonth()->subMonths($i);
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');
            $value = (int) $targetMonth->format('Ym');

            foreach (['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'] as $prefix) {
                cache()->put("{$prefix}:unique:{$dateType}:org={$orgId}", $value);
            }

            $expectedByLabel[$targetMonth->format("M \'y")] = $value;
        }

        $widget = new LiveHits;
        $labels = $widget->getGraphLabels();

        $getUniqueMonthlyData = new \ReflectionMethod($widget, 'getUniqueMonthlyData');
        $getUniqueMonthlyData->setAccessible(true);
        $data = $getUniqueMonthlyData->invoke($widget, false);

        $byLabel = \array_combine($labels, $data);

        $this->assertSame(202602, $byLabel["Feb '26"], 'February data did not land in the Feb bucket.');
        $this->assertSame($expectedByLabel, $byLabel, 'Each month must carry its own value in the unique series.');
    }

    /**
     * The "Last Month" filter highlights a bar by matching a month label; that selector used
     * a bare subMonth() which overflowed on month-end days (May 31 -> "May '26" instead of
     * "Apr '26"), lighting up the wrong bar. Guards getHighlightedMonthsFromFilter().
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_last_month_highlight_targets_the_correct_bar_on_month_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new UniqueScanCounts;
        $widget->date = 'last_month';

        $method = new \ReflectionMethod($widget, 'getHighlightedMonthsFromFilter');
        $method->setAccessible(true);
        $highlighted = $method->invoke($widget);

        $this->assertSame(["Apr '26"], $highlighted, 'Last-month highlight overflowed to the wrong month.');
        $this->assertContains(
            $highlighted[0],
            $widget->getGraphLabels(),
            'Highlighted month must correspond to an actual bar label.',
        );
    }
}
