<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\KitHistoricPositionsQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class KitHistoricPositionsQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const MAPPING = 'TEST-KIT-2185';

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_omitting_bbox_reproduces_todays_rows(): void
    {
        $base = now()->subDays(2)->setTime(12, 0, 0);
        $this->insertScPosition([-97.7400, 30.3000], $base->copy());
        $this->insertScPosition([-97.5000, 30.5000], $base->copy()->addSeconds(30));

        $positions = KitHistoricPositionsQuery::query([
            'kit_mapping' => self::MAPPING,
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
        ]);

        $this->assertCount(2, $positions);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_bbox_restricts_to_points_inside_the_viewport(): void
    {
        $base = now()->subDays(2)->setTime(12, 0, 0);
        // Inside the bbox (south=30/north=31, west=-98/east=-97).
        $this->insertScPosition([-97.5000, 30.5000], $base->copy());
        // Well east of the bbox.
        $this->insertScPosition([-90.0000, 30.5000], $base->copy()->addSeconds(30));

        $positions = KitHistoricPositionsQuery::query([
            'kit_mapping' => self::MAPPING,
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
            'north' => 31.0,
            'south' => 30.0,
            'east' => -97.0,
            'west' => -98.0,
        ]);

        $this->assertCount(1, $positions);
        $this->assertEqualsWithDelta(-97.5000, $positions[0]['lng'], 0.0001);
        $this->assertEqualsWithDelta(30.5000, $positions[0]['lat'], 0.0001);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_partial_bbox_is_ignored_like_no_bbox(): void
    {
        // Only three of the four bounds present (west missing) — must behave
        // like no bbox at all rather than filtering on the given three.
        $base = now()->subDays(2)->setTime(12, 0, 0);
        $this->insertScPosition([-97.5000, 30.5000], $base->copy());
        $this->insertScPosition([-90.0000, 30.5000], $base->copy()->addSeconds(30));

        $positions = KitHistoricPositionsQuery::query([
            'kit_mapping' => self::MAPPING,
            'start' => $base->copy()->subMinute()->toDateTimeString(),
            'end' => $base->copy()->addMinute()->toDateTimeString(),
            'north' => 31.0,
            'south' => 30.0,
            'east' => -97.0,
        ]);

        $this->assertCount(2, $positions);
    }

    /**
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertScPosition(array $point, Carbon $at): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.sc_positions')->insert([
            'kit_mapping' => self::MAPPING,
            'geo' => "({$point[0]}, {$point[1]})",
            'timestamp_utc' => $at->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ]);
    }
}
