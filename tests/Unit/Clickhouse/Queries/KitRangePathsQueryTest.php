<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\ClickhouseQuery;
use App\Clickhouse\Queries\KitRangePathsQuery;
use App\Support\Scout\HistoricPathBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * `handle()` returns one row per RENDERED segment (mapping, trip_date,
 * path_json) rather than a flat point list — the hour split, the >500ft break
 * and the <2-point drop all moved out of HistoricPathBuilder and into the
 * query, so a whole-fleet selection never materializes per-point PHP arrays.
 * Most assertions here flatten the segments back to points via
 * {@see self::flattenPoints()} so the decimation/dedup/cap behaviours stay
 * directly comparable.
 */
class KitRangePathsQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const MAPPING = 'test-kit-2177';

    /** ~29m of longitude per step at these latitudes: over DEDUP_METERS (5m), well under the 152.4m break. */
    private const CONTINUOUS_STEP_DEGREES = 0.0003;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-20 18:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_dense_continuous_drive_produces_ordered_points(): void
    {
        $base = now()->subDays(2)->setTime(12, 0, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;
        for ($i = 0; $i < 4; $i++) {
            $this->insertScPosition(self::MAPPING, [-97.7400 + ($i * $step), 30.3000], $base->copy()->addSeconds($i * 3));
        }

        $segments = KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ]);

        // One continuous segment — no hour boundary, no >500ft jump.
        $this->assertCount(1, $segments);
        $this->assertSame(self::MAPPING, $segments[0]['mapping']);

        $points = $this->flattenPoints($segments);
        $this->assertCount(4, $points);

        // Chronological order shows up as monotonically increasing longitude.
        $longitudes = array_column($points, 0);
        $sorted = $longitudes;
        sort($sorted);
        $this->assertSame($sorted, $longitudes);
        $this->assertEqualsWithDelta(-97.7400, $points[0][0], 0.0001);
        $this->assertEqualsWithDelta(30.3000, $points[0][1], 0.0001);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_points_are_chronologically_ordered_despite_unordered_aggregation(): void
    {
        $base = now()->subDays(2)->setTime(9, 0, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;

        // Insert out of chronological order — groupArray does not preserve
        // insertion order, so the query's arraySort must correct this.
        $this->insertScPosition(self::MAPPING, [-97.0000 + (2 * $step), 30.0100], $base->copy()->addSeconds(6));
        $this->insertScPosition(self::MAPPING, [-97.0000, 30.0100], $base->copy());
        $this->insertScPosition(self::MAPPING, [-97.0000 + $step, 30.0100], $base->copy()->addSeconds(3));

        $points = $this->flattenPoints(KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ]));

        $this->assertCount(3, $points);
        $this->assertEqualsWithDelta(-97.0000, $points[0][0], 0.0001);
        $this->assertEqualsWithDelta(-97.0000 + $step, $points[1][0], 0.0001);
        $this->assertEqualsWithDelta(-97.0000 + (2 * $step), $points[2][0], 0.0001);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_a_20_minute_in_hour_drive_is_no_longer_truncated_at_200(): void
    {
        $base = now()->subDays(2)->setTime(10, 0, 0);

        $pointCount = 400; // 400 x 3s = 1200s = 20 minutes, well under the 1200-cap ceiling.
        $rows = [];
        for ($i = 0; $i < $pointCount; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-97.0000 + ($i * self::CONTINUOUS_STEP_DEGREES), 30.0000], $base->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $segments = KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHours(2)->toDateTimeString(),
        ]);

        // Under the OLD 200-point cap this would have been truncated in half.
        $this->assertCount(1, $segments);
        $points = $this->flattenPoints($segments);
        $this->assertCount($pointCount, $points);
        $this->assertEqualsWithDelta(-97.0000, $points[0][0], 0.00001);
        $this->assertEqualsWithDelta(-97.0000 + (($pointCount - 1) * self::CONTINUOUS_STEP_DEGREES), $points[$pointCount - 1][0], 0.00001);
    }

    /** 1200 (3600s / 3s decimation) is the natural per-hour ceiling; a full hour of driving survives whole. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_1200_point_cap_preserves_a_full_clock_hour_without_truncation(): void
    {
        $base = now()->subDays(2)->setTime(13, 0, 0);

        $pointCount = 1200; // Exactly fills the 13:00-13:59:57 hour at 3s cadence.
        $rows = [];
        for ($i = 0; $i < $pointCount; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-96.0000 + ($i * self::CONTINUOUS_STEP_DEGREES), 31.0000], $base->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $points = $this->flattenPoints(KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHours(2)->toDateTimeString(),
        ]));

        $this->assertLessThanOrEqual(1200, count($points));
        $this->assertCount($pointCount, $points);
        $this->assertEqualsWithDelta(-96.0000, $points[0][0], 0.00001);
        $this->assertEqualsWithDelta(-96.0000 + (($pointCount - 1) * self::CONTINUOUS_STEP_DEGREES), $points[$pointCount - 1][0], 0.00001);
    }

    /** Cap is per (kit, hour): two full hours (2400 points) both survive, not globally truncated to 1200. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_cap_is_scoped_per_kit_hour_not_applied_globally(): void
    {
        $hourOne = now()->subDays(2)->setTime(8, 0, 0);
        $hourTwo = now()->subDays(2)->setTime(9, 0, 0);

        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-95.0000 + ($i * self::CONTINUOUS_STEP_DEGREES), 32.0000], $hourOne->copy()->addSeconds($i * 3));
        }
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-94.0000 + ($i * self::CONTINUOUS_STEP_DEGREES), 33.0000], $hourTwo->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $segments = KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $hourOne->copy()->subMinute()->toDateTimeString(),
            'end' => $hourTwo->copy()->addHours(2)->toDateTimeString(),
        ]);

        // The hour boundary splits them; neither hour is truncated.
        $this->assertCount(2, $segments);
        $this->assertCount(2400, $this->flattenPoints($segments));
    }

    /** The break replaces HistoricPathBuilder's 500ft split: a mid-hour jump starts a new segment rather than drawing a bogus connector. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_a_jump_over_the_break_distance_splits_one_hour_into_two_segments(): void
    {
        $base = now()->subDays(2)->setTime(14, 0, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;

        // Two continuous pairs inside the SAME hour, separated by ~4km — far
        // over the 152.4m break, so the line must break rather than connect.
        $this->insertScPosition(self::MAPPING, [-91.0000, 36.0000], $base->copy());
        $this->insertScPosition(self::MAPPING, [-91.0000 + $step, 36.0000], $base->copy()->addSeconds(3));
        $this->insertScPosition(self::MAPPING, [-90.9500, 36.0000], $base->copy()->addSeconds(6));
        $this->insertScPosition(self::MAPPING, [-90.9500 + $step, 36.0000], $base->copy()->addSeconds(9));

        $segments = KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ]);

        $this->assertCount(2, $segments);

        foreach ($segments as $segment) {
            $this->assertCount(2, json_decode($segment['path_json'], true, flags: JSON_THROW_ON_ERROR));
        }
    }

    /** HistoricPathBuilder drops <2-point segments; so must the query, or deck.gl gets a degenerate path. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_segments_shorter_than_two_points_are_dropped(): void
    {
        $base = now()->subDays(2)->setTime(15, 30, 0);

        // Three lone points, each ~4km from the next — every pair breaks, so
        // every candidate segment is a single point and nothing is renderable.
        $this->insertScPosition(self::MAPPING, [-91.0000, 37.0000], $base->copy());
        $this->insertScPosition(self::MAPPING, [-90.9500, 37.0000], $base->copy()->addSeconds(3));
        $this->insertScPosition(self::MAPPING, [-90.9000, 37.0000], $base->copy()->addSeconds(6));

        $segments = KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ]);

        $this->assertSame([], $segments);
    }

    /**
     * The whole point of moving the split into SQL: it must agree with the
     * PHP implementation every other caller still uses.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_clickhouse_segmentation_matches_historic_path_builder(): void
    {
        $base = now()->subDays(2)->setTime(7, 58, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;

        $rows = [];
        // A continuous run that crosses the 08:00 hour boundary…
        for ($i = 0; $i < 8; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-89.0000 + ($i * $step), 38.0000], $base->copy()->addSeconds($i * 30));
        }
        // …then a >500ft jump, then another continuous run…
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-88.9000 + ($i * $step), 38.0000], $base->copy()->addMinutes(6)->addSeconds($i * 3));
        }
        // …then a lone point that must be dropped by both implementations.
        $rows[] = $this->scPositionRow(self::MAPPING, [-88.5000, 38.0000], $base->copy()->addMinutes(20));
        $this->insertScPositionsBulk($rows);

        $arguments = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHour()->toDateTimeString(),
            'timezone' => 'UTC',
        ];

        $expected = HistoricPathBuilder::build($this->rawDecimatedPoints($arguments), 'UTC');
        $actual = KitRangePathsQuery::query($arguments);

        $this->assertNotEmpty($actual);
        $this->assertSameSize($expected, $actual);

        foreach ($expected as $index => $segment) {
            $this->assertSame($segment['trip_date'], $actual[$index]['trip_date']);

            $path = json_decode($actual[$index]['path_json'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSameSize($segment['path'], $path);

            foreach ($segment['path'] as $pointIndex => $point) {
                // COORDINATE_DECIMALS rounds on the wire (~1.1m, under the
                // dedup floor) and ClickHouse rounds half-to-even where PHP
                // rounds half-away-from-zero, so compare within one unit.
                $this->assertEqualsWithDelta($point[0], $path[$pointIndex][0], 0.00001);
                $this->assertEqualsWithDelta($point[1], $path[$pointIndex][1], 0.00001);
            }
        }
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_stationary_dwell_collapses_while_a_moving_run_survives(): void
    {
        $base = now()->subDays(2)->setTime(15, 0, 0);

        $rows = [];
        // 100 stationary points (0m apart) — a parked/idle scanner.
        for ($i = 0; $i < 100; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-93.0000, 34.0000], $base->copy()->addSeconds($i * 3));
        }
        // 10 moving points right after (~86m apart — over DEDUP_METERS, under the break).
        $movingStart = $base->copy()->addSeconds(100 * 3);
        for ($i = 0; $i < 10; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-93.0000 + (($i + 1) * 0.001), 34.0000], $movingStart->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $points = $this->flattenPoints(KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHours(1)->toDateTimeString(),
        ]));

        // 110 raw decimated points collapse to 11: 1 stationary + 10 moving.
        $this->assertCount(11, $points);

        // The single kept stationary point is the FIRST one (fold keeps the
        // first candidate when its accumulator is empty).
        $this->assertEqualsWithDelta(-93.0000, $points[0][0], 0.00001);

        // Every moving point survives, in chronological/ascending-lng order.
        for ($i = 1; $i <= 10; $i++) {
            $this->assertEqualsWithDelta(-93.0000 + ($i * 0.001), $points[$i][0], 0.00001);
        }
    }

    /** Dedup measures from the last KEPT point (cumulative), so a sub-5m/step crawl still accumulates distance and survives. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_slow_crawl_is_preserved_not_collapsed_to_a_single_point(): void
    {
        $base = now()->subDays(2)->setTime(16, 0, 0);

        $pointCount = 30;
        $latStepDegrees = 3.0 / 111320; // ~3m of latitude per step.
        $rows = [];
        for ($i = 0; $i < $pointCount; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-92.0000, 35.0000 + ($i * $latStepDegrees)], $base->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $points = $this->flattenPoints(KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHours(1)->toDateTimeString(),
        ]));

        // NOT collapsed to 1 (that would mean the crawl vanished) and NOT
        // the full 30 (some sub-5m points are legitimately deduped) — every
        // ~2 steps (~6m of cumulative travel) is kept.
        $this->assertGreaterThan(1, count($points));
        $this->assertLessThan($pointCount, count($points));
        $this->assertCount(15, $points);

        // Chronological and monotonically northward (the crawl's shape survives).
        $latitudes = array_column($points, 1);
        $sorted = $latitudes;
        sort($sorted);
        $this->assertSame($sorted, $latitudes);
        $this->assertEqualsWithDelta(35.0000, $points[0][1], 0.00001);
    }

    /** A widened dedup distance is how a broad selection is bounded — decimation stays pinned (DEV-2221). */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_widening_dedup_meters_reduces_the_rendered_point_count(): void
    {
        $base = now()->subDays(2)->setTime(11, 30, 0);

        $rows = [];
        // 60 points ~11m apart: kept at 5m, mostly collapsed at 50m.
        for ($i = 0; $i < 60; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-90.0000 + ($i * 0.00012), 39.0000], $base->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $arguments = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHour()->toDateTimeString(),
        ];

        $tight = KitRangePathsQuery::countPoints($arguments + ['dedup_meters' => 5.0]);
        $wide = KitRangePathsQuery::countPoints($arguments + ['dedup_meters' => 50.0]);

        $this->assertGreaterThan($wide, $tight);
        $this->assertGreaterThan(1, $wide, 'a widened dedup must thin the route, never erase it');
        $this->assertSame($wide, count($this->flattenPoints(
            KitRangePathsQuery::query($arguments + ['dedup_meters' => 50.0])
        )));
    }

    /** Dedup above the break distance would shred every route into dropped single points, so it is clamped. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_dedup_meters_is_clamped_below_the_break_distance(): void
    {
        $base = now()->subDays(2)->setTime(12, 30, 0);

        $rows = [];
        // ~102m per step: comfortably above the 50.8m clamp ceiling (so no
        // point is deduped away) and below the 152.4m break (so nothing shreds).
        for ($i = 0; $i < 40; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-87.0000 + ($i * 0.0012), 40.0000], $base->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $arguments = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHour()->toDateTimeString(),
        ];

        // An absurd request is clamped to the same result as the ceiling, and
        // still renders a route rather than an empty map.
        $clamped = KitRangePathsQuery::query($arguments + ['dedup_meters' => 100000.0]);

        $this->assertNotEmpty($clamped);
        $this->assertGreaterThan(1, count($this->flattenPoints($clamped)));
    }

    /** Hour buckets are cut in the viewer's timezone, so a half-hour-offset zone splits on ITS clock hour, not UTC's. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_hour_buckets_follow_the_requested_timezone(): void
    {
        // 04:20 and 04:40 UTC are the same UTC hour, but 09:50 and 10:10 in
        // Asia/Kolkata (+05:30) — a different local hour on each side.
        $base = now()->subDays(2)->setTime(4, 20, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;

        $this->insertScPosition(self::MAPPING, [-86.0000, 41.0000], $base->copy());
        $this->insertScPosition(self::MAPPING, [-86.0000 + $step, 41.0000], $base->copy()->addSeconds(3));
        $this->insertScPosition(self::MAPPING, [-86.0000 + (2 * $step), 41.0000], $base->copy()->addMinutes(20));
        $this->insertScPosition(self::MAPPING, [-86.0000 + (3 * $step), 41.0000], $base->copy()->addMinutes(20)->addSeconds(3));

        $arguments = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHour()->toDateTimeString(),
        ];

        $this->assertCount(1, KitRangePathsQuery::query($arguments + ['timezone' => 'UTC']));
        $this->assertCount(2, KitRangePathsQuery::query($arguments + ['timezone' => 'Asia/Kolkata']));
    }

    /** An unknown timezone string is interpolated into raw SQL, so it must never reach ClickHouse. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_an_invalid_timezone_falls_back_to_utc_instead_of_reaching_sql(): void
    {
        $base = now()->subDays(2)->setTime(13, 30, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;

        $this->insertScPosition(self::MAPPING, [-85.0000, 42.0000], $base->copy());
        $this->insertScPosition(self::MAPPING, [-85.0000 + $step, 42.0000], $base->copy()->addSeconds(3));

        $arguments = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHour()->toDateTimeString(),
        ];

        $this->assertEquals(
            KitRangePathsQuery::query($arguments + ['timezone' => 'UTC']),
            KitRangePathsQuery::query($arguments + ['timezone' => "Not/AZone'--"])
        );
    }

    /** countPoints() must equal the flattened rendered total — the dedup'd count (110 raw -> 11), not the raw decimated count. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_count_points_matches_the_actual_post_dedup_rendered_total(): void
    {
        $base = now()->subDays(2)->setTime(17, 0, 0);

        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-93.5000, 34.5000], $base->copy()->addSeconds($i * 3));
        }
        $movingStart = $base->copy()->addSeconds(100 * 3);
        for ($i = 0; $i < 10; $i++) {
            $rows[] = $this->scPositionRow(self::MAPPING, [-93.5000 + (($i + 1) * 0.001), 34.5000], $movingStart->copy()->addSeconds($i * 3));
        }
        $this->insertScPositionsBulk($rows);

        $arguments = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHours(1)->toDateTimeString(),
        ];

        $count = KitRangePathsQuery::countPoints($arguments);

        $this->assertSame(11, $count);
        $this->assertSame(count($this->flattenPoints(KitRangePathsQuery::query($arguments))), $count);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_count_points_is_zero_for_no_mappings_or_an_empty_window(): void
    {
        $base = now()->subDays(2)->setTime(18, 0, 0);
        $this->insertScPosition(self::MAPPING, [-97.5000, 30.5000], $base->copy());

        $this->assertSame(0, KitRangePathsQuery::countPoints([]));

        $this->assertSame(0, KitRangePathsQuery::countPoints([
            'mappings' => [self::MAPPING],
            // A window that excludes the seeded position entirely.
            'start' => $base->copy()->addHours(5)->toDateTimeString(),
            'end' => $base->copy()->addHours(6)->toDateTimeString(),
        ]));
    }

    /** DEV-2374: optional viewport filters GPS before assembly; count + handle agree. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_optional_bbox_filters_source_points_before_assembly(): void
    {
        $base = now()->subDays(2)->setTime(12, 0, 0);
        $this->insertScPositionsBulk([
            $this->scPositionRow(self::MAPPING, [-74.5, 39.5], $base->copy()),
            $this->scPositionRow(self::MAPPING, [-74.501, 39.5], $base->copy()->addSeconds(3)),
            $this->scPositionRow(self::MAPPING, [-80.0, 45.0], $base->copy()->addSeconds(6)),
            $this->scPositionRow(self::MAPPING, [-80.001, 45.0], $base->copy()->addSeconds(9)),
        ]);

        $window = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addHour()->toDateTimeString(),
            'dedup_meters' => 50.0,
        ];
        $bbox = [
            'north' => 40.0,
            'south' => 39.0,
            'east' => -74.0,
            'west' => -75.0,
        ];

        $unbounded = $this->flattenPoints(KitRangePathsQuery::query($window));
        $bounded = $this->flattenPoints(KitRangePathsQuery::query($window + $bbox));

        $this->assertGreaterThan(count($bounded), count($unbounded));
        $this->assertSame(KitRangePathsQuery::countPoints($window + $bbox), count($bounded));

        foreach ($bounded as $point) {
            [$lng, $lat] = $point;
            $this->assertGreaterThanOrEqual(39.0, $lat);
            $this->assertLessThanOrEqual(40.0, $lat);
            $this->assertGreaterThanOrEqual(-75.0, $lng);
            $this->assertLessThanOrEqual(-74.0, $lng);
        }
    }

    /** A lone point is counted (the guard is a pre-split upper bound) but renders nothing. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_single_point_window_degrades_cleanly(): void
    {
        $base = now()->subDays(2)->setTime(11, 0, 0);
        $this->insertScPosition(self::MAPPING, [-97.5000, 30.5000], $base->copy());

        $arguments = [
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ];

        $this->assertSame(1, KitRangePathsQuery::countPoints($arguments));
        $this->assertSame([], KitRangePathsQuery::query($arguments));
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_zero_point_window_returns_empty(): void
    {
        $base = now()->subDays(2)->setTime(11, 0, 0);
        $this->insertScPosition(self::MAPPING, [-97.5000, 30.5000], $base->copy());

        $segments = KitRangePathsQuery::query([
            'mappings' => [self::MAPPING],
            // A window that excludes the seeded position entirely.
            'start' => $base->copy()->addHours(5)->toDateTimeString(),
            'end' => $base->copy()->addHours(6)->toDateTimeString(),
        ]);

        $this->assertSame([], $segments);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_mapping_with_no_rows_in_window_is_absent_not_an_error(): void
    {
        $base = now()->subDays(2)->setTime(12, 0, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;
        $this->insertScPosition(self::MAPPING, [-97.5000, 30.5000], $base->copy());
        $this->insertScPosition(self::MAPPING, [-97.5000 + $step, 30.5000], $base->copy()->addSeconds(3));

        $segments = KitRangePathsQuery::query([
            'mappings' => [self::MAPPING, 'no-such-mapping'],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ]);

        $this->assertCount(1, $segments);
        $this->assertSame(self::MAPPING, $segments[0]['mapping']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_mapping_match_is_case_insensitive(): void
    {
        $base = now()->subDays(2)->setTime(12, 0, 0);
        $step = self::CONTINUOUS_STEP_DEGREES;
        $this->insertScPosition('TEST-Kit-2177', [-97.5000, 30.5000], $base->copy());
        $this->insertScPosition('TEST-Kit-2177', [-97.5000 + $step, 30.5000], $base->copy()->addSeconds(3));

        $segments = KitRangePathsQuery::query([
            // Caller always lowercases (controller resolves via lower(kit_mapping)).
            'mappings' => [self::MAPPING],
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ]);

        $this->assertCount(1, $segments);
        $this->assertSame(self::MAPPING, $segments[0]['mapping']);
    }

    /** kit_mapping can contain '-' and buildCacheKey joins arrays with '-', so a raw mapping list would collide across sets/tenants; mappings_key + org_id disambiguate. */
    public function test_cache_key_is_scoped_by_org_id_and_avoids_mapping_join_collisions(): void
    {
        $reflection = new \ReflectionMethod(ClickhouseQuery::class, 'buildCacheKey');
        $reflection->setAccessible(true);

        $baseArgs = [
            'org_id' => 1,
            'mappings_key' => md5(implode(',', ['a-b', 'c'])),
            'start' => '2026-07-19 00:00:00',
            'end' => '2026-07-19 23:59:59',
        ];

        $orgOneKey = $reflection->invoke(null, $baseArgs);
        $orgTwoKey = $reflection->invoke(null, ['org_id' => 2] + $baseArgs);

        $this->assertNotSame($orgOneKey, $orgTwoKey, 'cache key must be scoped by org_id');

        // Two DIFFERENT mapping sets that collide under a naive '-' join
        // (['a-b','c'] vs ['a','b-c'] both join to "a-b-c") must still
        // produce distinct keys once routed through mappings_key.
        $setOneKey = $reflection->invoke(null, [
            'org_id' => 1,
            'mappings_key' => md5(implode(',', ['a-b', 'c'])),
            'start' => '2026-07-19 00:00:00',
            'end' => '2026-07-19 23:59:59',
        ]);
        $setTwoKey = $reflection->invoke(null, [
            'org_id' => 1,
            'mappings_key' => md5(implode(',', ['a', 'b-c'])),
            'start' => '2026-07-19 00:00:00',
            'end' => '2026-07-19 23:59:59',
        ]);

        $this->assertNotSame($setOneKey, $setTwoKey, 'mappings_key must disambiguate colliding mapping sets');

        // The raw mapping ARRAY passed under a plain 'mappings' key IS the
        // bug being fixed: both sets below join to the same '-'-delimited
        // string, so this documents exactly why the caller must not do this.
        $naiveOne = $reflection->invoke(null, ['org_id' => 1, 'mappings' => ['a-b', 'c']]);
        $naiveTwo = $reflection->invoke(null, ['org_id' => 1, 'mappings' => ['a', 'b-c']]);
        $this->assertSame($naiveOne, $naiveTwo, 'documents the naive-join collision this fix avoids via mappings_key');
    }

    /** The dedup/cap behaviours are per-point, so flatten the segment paths back into one ordered point list.
     *
     * @param  list<array{mapping: string, trip_date: string, path_json: string}>  $segments
     * @return list<array{0: float, 1: float}>
     */
    private function flattenPoints(array $segments): array
    {
        $points = [];

        foreach ($segments as $segment) {
            foreach (json_decode($segment['path_json'], true, flags: JSON_THROW_ON_ERROR) as $point) {
                $points[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $points;
    }

    /**
     * The decimated/dedup'd/capped points BEFORE any splitting, shaped for
     * HistoricPathBuilder so the parity test compares the two implementations
     * of the split alone.
     *
     * @return list<array{lng: float, lat: float, timestamp_utc: string}>
     */
    private function rawDecimatedPoints(array $arguments): array
    {
        $method = new \ReflectionMethod(KitRangePathsQuery::class, 'hourlyPointsQuery');
        $method->setAccessible(true);

        $timezone = $arguments['timezone'] ?? 'UTC';
        $rows = $method->invoke(null, $arguments['mappings'], $arguments['start'], $arguments['end'], 5.0, $timezone)
            ->orderByRaw("mapping, toStartOfHour(toDateTime(ts, 'UTC'), '{$timezone}')")
            ->get();

        $points = [];

        foreach ($rows as $row) {
            foreach ($row['points'] ?? $row->points as $point) {
                $points[] = [
                    'lng' => (float) $point[1],
                    'lat' => (float) $point[2],
                    'timestamp_utc' => (string) $point[0],
                ];
            }
        }

        return $points;
    }

    /**
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertScPosition(string $mapping, array $point, Carbon $at): void
    {
        $this->insertScPositionsBulk([$this->scPositionRow($mapping, $point, $at)]);
    }

    /**
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     * @return array{kit_mapping: string, geo: string, timestamp_utc: string, speed: float, heading: float}
     */
    private function scPositionRow(string $mapping, array $point, Carbon $at): array
    {
        return [
            'kit_mapping' => $mapping,
            'geo' => "({$point[0]}, {$point[1]})",
            'timestamp_utc' => $at->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ];
    }

    /**
     * @param  list<array{kit_mapping: string, geo: string, timestamp_utc: string, speed: float, heading: float}>  $rows
     */
    private function insertScPositionsBulk(array $rows): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.sc_positions')->insert($rows);
    }
}
