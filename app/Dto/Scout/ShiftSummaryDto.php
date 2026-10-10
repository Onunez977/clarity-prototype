<?php

declare(strict_types=1);

namespace App\Dto\Scout;

use App\Enums\ShiftEndTypeEnum;
use App\Models\KitShift;
use App\Services\Scout\BuildShiftSummary;
use Illuminate\Support\Carbon;

/**
 * Summary data passed to the EndOfShiftSummary mailable.
 *
 * Built by {@see BuildShiftSummary} once per shift email
 * and rendered by `resources/views/mail/scout/end-of-shift-summary.blade.php`.
 *
 * `zones` only contains zones with ≥ 1 scan. `mapUrl` may be null when
 * services.mapbox.token is unset (mirrors the existing missed-opportunities
 * mailable pattern).
 */
final readonly class ShiftSummaryDto
{
    /**
     * @param  array<int, ZoneSummaryDto>  $zones
     */
    public function __construct(
        public int $shiftId,
        public int $kitId,
        public string $kitName,
        public ?string $driverName,
        public ?string $driverEmail,
        public Carbon $startedAt,
        public Carbon $endedAt,
        public Carbon $windowStartedAt,
        public Carbon $windowEndedAt,
        public ShiftEndTypeEnum $endType,
        public float $totalMiles,
        public int $totalScans,
        public int $totalHits,
        public array $zones,
        public ?string $mapUrl,
    ) {}

    public function hasActivity(): bool
    {
        return $this->totalScans > 0 || $this->totalHits > 0 || $this->totalMiles > 0.0;
    }

    public function isTruncated(): bool
    {
        return $this->windowStartedAt->gt($this->startedAt);
    }

    public function activityWindowHours(): int
    {
        return BuildShiftSummary::SUMMARY_WINDOW_HOURS;
    }

    public function durationMinutes(): int
    {
        // abs() guards Carbon 3's signed diffInMinutes so a normal end-after-start
        // shift reports a positive duration (matching the abs() guards in
        // EnsureOpenShift / CloseInactiveShiftsCommand).
        return (int) abs($this->endedAt->diffInMinutes($this->startedAt));
    }

    public static function forShift(
        KitShift $shift,
        string $kitName,
        ?string $driverName,
        ?string $driverEmail,
        float $totalMiles,
        int $totalScans,
        int $totalHits,
        array $zones,
        ?string $mapUrl,
        Carbon $windowStartedAt,
        Carbon $windowEndedAt,
    ): self {
        return new self(
            shiftId: $shift->id,
            kitId: $shift->kit_id,
            kitName: $kitName,
            driverName: $driverName,
            driverEmail: $driverEmail,
            startedAt: $shift->started_at,
            endedAt: $shift->ended_at ?? Carbon::now(),
            windowStartedAt: $windowStartedAt,
            windowEndedAt: $windowEndedAt,
            endType: $shift->end_type ?? ShiftEndTypeEnum::Confirmed,
            totalMiles: $totalMiles,
            totalScans: $totalScans,
            totalHits: $totalHits,
            zones: $zones,
            mapUrl: $mapUrl,
        );
    }
}
