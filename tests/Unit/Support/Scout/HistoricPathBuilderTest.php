<?php

namespace Tests\Unit\Support\Scout;

use App\Support\Scout\HistoricPathBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HistoricPathBuilderTest extends TestCase
{
    /**
     * @return array{lng: float, lat: float, timestamp_utc: string}
     */
    private function position(float $lng, float $lat, string $ts): array
    {
        return ['lng' => $lng, 'lat' => $lat, 'timestamp_utc' => $ts];
    }

    #[Test]
    public function continuous_drive_within_one_hour_is_a_single_segment(): void
    {
        $segments = HistoricPathBuilder::build([
            $this->position(-97.7400, 30.3000, '2026-06-01 15:00:00'),
            $this->position(-97.7405, 30.3005, '2026-06-01 15:00:05'),
            $this->position(-97.7410, 30.3010, '2026-06-01 15:00:10'),
        ], 'UTC');

        $this->assertCount(1, $segments);
        $this->assertCount(3, $segments[0]['path']);
        $this->assertSame('2026-06-01', $segments[0]['trip_date']);
    }

    #[Test]
    public function a_jump_over_the_threshold_breaks_the_line(): void
    {
        // First two points are ~50ft apart (one segment); the third jumps ~3 miles
        // (a power-off-and-drive gap) and must start a new segment.
        $segments = HistoricPathBuilder::build([
            $this->position(-97.7400, 30.3000, '2026-06-01 15:00:00'),
            $this->position(-97.7401, 30.3001, '2026-06-01 15:00:05'),
            $this->position(-97.7900, 30.3400, '2026-06-01 15:00:10'),
            $this->position(-97.7901, 30.3401, '2026-06-01 15:00:15'),
        ], 'UTC');

        $this->assertCount(2, $segments);
        $this->assertCount(2, $segments[0]['path']);
        $this->assertCount(2, $segments[1]['path']);
    }

    #[Test]
    public function points_split_across_an_hour_boundary_become_separate_buckets(): void
    {
        $segments = HistoricPathBuilder::build([
            $this->position(-97.7400, 30.3000, '2026-06-01 15:59:00'),
            $this->position(-97.7405, 30.3005, '2026-06-01 15:59:30'),
            $this->position(-97.7410, 30.3010, '2026-06-01 16:00:10'),
            $this->position(-97.7415, 30.3015, '2026-06-01 16:00:40'),
        ], 'UTC');

        $this->assertCount(2, $segments);
        foreach ($segments as $segment) {
            $this->assertCount(2, $segment['path']);
        }
    }

    #[Test]
    public function single_point_segments_are_dropped(): void
    {
        // A lone point in the 15:00 hour, then a real two-point drive in 16:00.
        $segments = HistoricPathBuilder::build([
            $this->position(-97.7400, 30.3000, '2026-06-01 15:30:00'),
            $this->position(-97.7410, 30.3010, '2026-06-01 16:00:00'),
            $this->position(-97.7415, 30.3015, '2026-06-01 16:00:05'),
        ], 'UTC');

        $this->assertCount(1, $segments);
        $this->assertCount(2, $segments[0]['path']);
    }

    #[Test]
    public function timezone_drives_the_local_date_and_hour_label(): void
    {
        // 02:30 UTC on the 2nd is 21:30 the previous evening in America/Chicago
        // (UTC-5 in June), so the segment's date and hour label must read local.
        $segments = HistoricPathBuilder::build([
            $this->position(-97.7400, 30.3000, '2026-06-02 02:30:00'),
            $this->position(-97.7405, 30.3005, '2026-06-02 02:30:05'),
        ], 'America/Chicago', debug: true);

        $this->assertCount(1, $segments);
        $this->assertSame('2026-06-01', $segments[0]['trip_date']);
        $this->assertSame('9:00 PM–10:00 PM', $segments[0]['label']);
    }

    #[Test]
    public function debug_mode_adds_a_per_hour_label_and_color(): void
    {
        $segments = HistoricPathBuilder::build([
            $this->position(-97.7400, 30.3000, '2026-06-01 15:00:00'),
            $this->position(-97.7405, 30.3005, '2026-06-01 15:00:05'),
        ], 'UTC', debug: true);

        $this->assertCount(1, $segments);
        $this->assertArrayHasKey('label', $segments[0]);
        $this->assertArrayHasKey('color', $segments[0]);
        $this->assertSame('3:00 PM–4:00 PM', $segments[0]['label']);
        $this->assertCount(3, $segments[0]['color']);
    }

    #[Test]
    public function debug_props_are_absent_by_default(): void
    {
        $segments = HistoricPathBuilder::build([
            $this->position(-97.7400, 30.3000, '2026-06-01 15:00:00'),
            $this->position(-97.7405, 30.3005, '2026-06-01 15:00:05'),
        ], 'UTC');

        $this->assertArrayNotHasKey('label', $segments[0]);
        $this->assertArrayNotHasKey('color', $segments[0]);
    }

    #[Test]
    public function no_positions_yields_no_segments(): void
    {
        $this->assertSame([], HistoricPathBuilder::build([], 'UTC'));
    }
}
