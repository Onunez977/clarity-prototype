<?php

namespace Tests\Unit\Support\Scout;

use App\Support\Scout\Haversine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HaversineTest extends TestCase
{
    #[Test]
    public function fewer_than_two_points_returns_zero(): void
    {
        $this->assertSame(0.0, Haversine::miles([]));
        $this->assertSame(0.0, Haversine::miles([[-97.7, 30.3]]));
    }

    #[Test]
    public function identical_points_return_approximately_zero(): void
    {
        $miles = Haversine::miles([
            [-97.7, 30.3],
            [-97.7, 30.3],
            [-97.7, 30.3],
        ]);

        $this->assertEqualsWithDelta(0.0, $miles, 0.0001);
    }

    #[Test]
    public function one_degree_of_latitude_is_about_69_miles(): void
    {
        // 1° latitude ≈ 69.09 miles. We allow 0.5 mile fudge for the spherical approximation.
        $miles = Haversine::miles([
            [-97.7, 30.0],
            [-97.7, 31.0],
        ]);

        $this->assertEqualsWithDelta(69.09, $miles, 0.5);
    }

    #[Test]
    public function distances_accumulate_across_segments(): void
    {
        $oneLeg = Haversine::miles([[-97.7, 30.0], [-97.7, 30.5]]);
        $twoLegs = Haversine::miles([[-97.7, 30.0], [-97.7, 30.5], [-97.7, 31.0]]);

        $this->assertEqualsWithDelta($oneLeg * 2, $twoLegs, 0.01);
    }

    #[Test]
    public function feet_between_two_points_matches_the_miles_conversion(): void
    {
        $from = [-97.7, 30.0];
        $to = [-97.7, 30.5];

        $expectedFeet = Haversine::miles([$from, $to]) * 5280;

        $this->assertEqualsWithDelta($expectedFeet, Haversine::feet($from, $to), 1.0);
    }

    #[Test]
    public function feet_between_identical_points_is_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, Haversine::feet([-97.7, 30.3], [-97.7, 30.3]), 0.0001);
    }
}
