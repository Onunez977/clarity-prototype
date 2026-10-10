<?php

namespace Tests\Unit\Dto\Scout;

use App\Dto\Scout\ShiftSummaryDto;
use App\Enums\ShiftEndTypeEnum;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShiftSummaryDtoTest extends TestCase
{
    #[Test]
    public function duration_minutes_is_positive_for_a_normal_shift(): void
    {
        // Carbon 3's diffInMinutes is signed; a normal end-after-start shift must
        // still report a positive duration in the summary email.
        $dto = $this->dto(
            Carbon::parse('2026-05-01 10:00:00'),
            Carbon::parse('2026-05-01 12:30:00'),
        );

        $this->assertSame(150, $dto->durationMinutes());
    }

    #[Test]
    public function duration_minutes_is_zero_for_an_instant_shift(): void
    {
        $at = Carbon::parse('2026-05-01 10:00:00');

        $this->assertSame(0, $this->dto($at, $at->copy())->durationMinutes());
    }

    #[Test]
    public function it_is_truncated_when_the_read_window_starts_after_the_shift(): void
    {
        $startedAt = Carbon::parse('2026-07-31 00:00:00', 'UTC');
        $endedAt = Carbon::parse('2026-08-17 17:18:50', 'UTC');
        $windowStart = $endedAt->copy()->subHours(8);

        $dto = new ShiftSummaryDto(
            shiftId: 1,
            kitId: 1,
            kitName: 'Kit 1',
            driverName: null,
            driverEmail: null,
            startedAt: $startedAt,
            endedAt: $endedAt,
            windowStartedAt: $windowStart,
            windowEndedAt: $endedAt,
            endType: ShiftEndTypeEnum::Confirmed,
            totalMiles: 3.2,
            totalScans: 412,
            totalHits: 1,
            zones: [],
            mapUrl: null,
        );

        $this->assertTrue($dto->isTruncated());
        $this->assertSame(8, $dto->activityWindowHours());
    }

    #[Test]
    public function it_is_not_truncated_when_the_read_window_matches_the_shift_start(): void
    {
        $startedAt = Carbon::parse('2026-08-17 14:18:50', 'UTC');
        $endedAt = Carbon::parse('2026-08-17 17:18:50', 'UTC');

        $dto = $this->dto($startedAt, $endedAt);

        $this->assertFalse($dto->isTruncated());
    }

    private function dto(Carbon $start, Carbon $end): ShiftSummaryDto
    {
        return new ShiftSummaryDto(
            shiftId: 1,
            kitId: 1,
            kitName: 'Kit 1',
            driverName: null,
            driverEmail: null,
            startedAt: $start,
            endedAt: $end,
            windowStartedAt: $start,
            windowEndedAt: $end,
            endType: ShiftEndTypeEnum::Confirmed,
            totalMiles: 0.0,
            totalScans: 0,
            totalHits: 0,
            zones: [],
            mapUrl: null,
        );
    }
}
