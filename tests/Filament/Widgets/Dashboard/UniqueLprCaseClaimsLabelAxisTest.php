<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\UniqueLprCaseClaims;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * REGRESSION (review round 2, lane 1 M1). Round 1 moved this card's LABEL axis to UTC to match
 * its UTC data axis -- but nothing pinned it: deleting getGraphLabels(),
 * getHighlightedMonthsFromFilter() and getDescription() left every test green and fully restored
 * the defect (all 13 bars labelled one month behind their data for ~5-6h at every UTC month
 * start). DashboardMonthLabelsTest exists to guard exactly this class of bug but iterates only
 * [RecoveryLeader, UniqueScanCounts]. These tests close it for this card.
 */
final class UniqueLprCaseClaimsLabelAxisTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 2026-08-01 02:00 UTC is still 2026-07-31 21:00 in America/Chicago -- the exact window in
     * which a viewer-timezone label axis disagrees with a UTC data axis.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_graph_labels_follow_utc_not_the_viewers_timezone(): void
    {
        Carbon::setTestNow('2026-08-01 02:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $labels = (new UniqueLprCaseClaims)->getGraphLabels();

        $this->assertCount(13, $labels);
        $this->assertSame("Aug '26", $labels[12], 'the newest label must be the UTC month, not the viewer-local July');
        $this->assertSame("Jul '26", $labels[11]);
        $this->assertSame("Aug '25", $labels[0]);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_highlighted_month_follows_utc_and_matches_a_real_label(): void
    {
        Carbon::setTestNow('2026-08-01 02:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new UniqueLprCaseClaims;
        $labels = $widget->getGraphLabels();

        $method = new ReflectionMethod($widget, 'getHighlightedMonthsFromFilter');
        $method->setAccessible(true);

        $widget->date = 'this_month';
        $this->assertSame(["Aug '26"], $method->invoke($widget));

        $widget->date = 'last_month';
        $this->assertSame(["Jul '26"], $method->invoke($widget));

        // A highlight that is not in the label set lights up no bar at all.
        foreach (['this_month', 'last_month'] as $date) {
            $widget->date = $date;
            foreach ($method->invoke($widget) as $highlight) {
                $this->assertContains($highlight, $labels, "highlight {$highlight} must match a rendered label");
            }
        }
    }

    /**
     * DEV-2651 t3, AC-4. The ratio dataset's right-hand axis: scales.y1 must exist,
     * position on the right, disable its own gridlines so they don't double up with the
     * left axis, and both axes must carry a title since they now plot different units.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_get_options_adds_a_titled_right_hand_axis_for_the_ratio_dataset(): void
    {
        // Review-4 R4-B: this used to run with NO authenticated user, so it asserted the ratio
        // axis renders for a viewer who cannot see the ratio at all. The axis is now gated on
        // canViewRatioFigure(), so the test must act as someone entitled to it.
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $widget = new SeriesStubbingLabelAxisWidget;

        $method = new ReflectionMethod($widget, 'getOptions');
        $method->setAccessible(true);
        $options = $method->invoke($widget);

        $this->assertArrayHasKey('y1', $options['scales']);
        $this->assertSame('right', $options['scales']['y1']['position']);
        $this->assertFalse($options['scales']['y1']['grid']['drawOnChartArea']);
        $this->assertNotEmpty($options['scales']['y']['title']['text'] ?? null, 'the left axis must carry a title');
        $this->assertNotEmpty($options['scales']['y1']['title']['text'] ?? null, 'the right axis must carry a title');
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_description_names_the_utc_months_not_the_base_class_literals(): void
    {
        Carbon::setTestNow('2026-08-01 02:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new SeriesStubbingLabelAxisWidget;
        $widget->date = 'this_month';
        $widget->series = [
            '2026-08-01' => ['all_claims' => 9, 'successful' => 9, 'deduped' => 8],
            '2026-07-01' => ['all_claims' => 5, 'successful' => 5, 'deduped' => 4],
        ];

        $description = (string) $widget->getDescription();

        $this->assertStringContainsString("Aug '26", $description);
        $this->assertStringContainsString("Jul '26", $description);
        $this->assertStringNotContainsString('Current Month', $description, 'the base literals imply the viewer-local month');
        $this->assertStringNotContainsString('Last Month', $description);
    }
}

/**
 * Returns a caller-supplied series instead of hitting ClickHouse, so the label axis can be
 * asserted against known buckets.
 *
 * Declared HERE, and named distinctly from UniqueLprCaseClaimsTest's equivalent, on purpose:
 * CI shards by test FILE, so borrowing a sibling file's stub passes locally (one process loads
 * every file) and dies with "class not found" on whichever shard lacks that sibling -- which is
 * exactly how this file broke CI. A shared name would instead fatal on redeclaration when both
 * files do land together.
 */
class SeriesStubbingLabelAxisWidget extends UniqueLprCaseClaims
{
    /** @var array<string, array{all_claims: int, successful: int, deduped: int}> */
    public array $series = [];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return $this->series;
    }

    /**
     * Review-4 R4-B: the right-hand axis is now conditional on the numerator being available,
     * so this stub has to supply one. Unstubbed it would reach real ClickHouse, the trait would
     * catch and set numeratorUnavailable, and the axis assertions would fail for a reason that
     * has nothing to do with what this test is about.
     *
     * @return array<string, int>
     */
    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        return [];
    }
}
