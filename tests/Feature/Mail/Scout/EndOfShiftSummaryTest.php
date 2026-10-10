<?php

namespace Tests\Feature\Mail\Scout;

use App\Dto\Scout\ShiftSummaryDto;
use App\Dto\Scout\ZoneSummaryDto;
use App\Enums\ShiftEndTypeEnum;
use App\Mail\Scout\EndOfShiftSummary;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EndOfShiftSummaryTest extends TestCase
{
    private function summary(array $overrides = []): ShiftSummaryDto
    {
        $defaults = [
            'shiftId' => 1,
            'kitId' => 42,
            'kitName' => 'Truck 42',
            'driverName' => 'Alex Driver',
            'driverEmail' => 'alex@example.com',
            'startedAt' => Carbon::parse('2026-05-26 08:00:00'),
            'endedAt' => Carbon::parse('2026-05-26 16:30:00'),
            'windowStartedAt' => Carbon::parse('2026-05-26 08:00:00'),
            'windowEndedAt' => Carbon::parse('2026-05-26 16:30:00'),
            'endType' => ShiftEndTypeEnum::Confirmed,
            'totalMiles' => 78.4,
            'totalScans' => 1240,
            'totalHits' => 5,
            'zones' => [
                new ZoneSummaryDto(id: 1, name: 'North Loop', color: '#2563eb', scans: 800, hits: 3, timeInZoneMinutes: 180),
                new ZoneSummaryDto(id: 2, name: 'Downtown', color: '#ef4444', scans: 440, hits: 2, timeInZoneMinutes: 110),
            ],
            'mapUrl' => 'https://example.com/static.png',
        ];

        return new ShiftSummaryDto(...array_merge($defaults, $overrides));
    }

    #[Test]
    public function confirmed_subject_includes_confirmed_label(): void
    {
        $mail = new EndOfShiftSummary($this->summary());
        $this->assertStringContainsString('Confirmed', $mail->subject);
        $this->assertStringContainsString('Truck 42', $mail->subject);
    }

    #[Test]
    public function assumed_subject_includes_assumed_label(): void
    {
        $mail = new EndOfShiftSummary($this->summary(['endType' => ShiftEndTypeEnum::Assumed]));
        $this->assertStringContainsString('Assumed', $mail->subject);
    }

    #[Test]
    public function rendered_email_contains_summary_metrics_and_zone_rows(): void
    {
        $mail = new EndOfShiftSummary($this->summary());
        $rendered = $mail->render();

        $this->assertStringContainsString('Truck 42', $rendered);
        $this->assertStringContainsString('Alex Driver', $rendered);
        $this->assertStringContainsString('78.4', $rendered);
        $this->assertStringContainsString('1,240', $rendered);
        $this->assertStringContainsString('Total Miles', $rendered);
        $this->assertStringContainsString('North Loop', $rendered);
        $this->assertStringContainsString('Downtown', $rendered);
        $this->assertStringContainsString('https://example.com/static.png', $rendered);
    }

    #[Test]
    public function rendered_email_shows_empty_state_for_zero_activity(): void
    {
        $mail = new EndOfShiftSummary($this->summary([
            'totalMiles' => 0.0,
            'totalScans' => 0,
            'totalHits' => 0,
            'zones' => [],
        ]));

        $rendered = $mail->render();

        $this->assertStringContainsString('No scans or GPS activity', $rendered);
        $this->assertStringNotContainsString('last 8 hours of this shift', $rendered);
    }

    #[Test]
    public function rendered_email_discloses_when_metrics_cover_only_the_last_eight_hours(): void
    {
        $mail = new EndOfShiftSummary($this->summary([
            'startedAt' => Carbon::parse('2026-07-31 00:00:00', 'UTC'),
            'endedAt' => Carbon::parse('2026-08-17 17:18:50', 'UTC'),
            'windowStartedAt' => Carbon::parse('2026-08-17 09:18:50', 'UTC'),
            'windowEndedAt' => Carbon::parse('2026-08-17 17:18:50', 'UTC'),
        ]));

        $rendered = $mail->render();

        $this->assertStringContainsString('Activity shown', $rendered);
        $this->assertStringContainsString('last 8 hours', $rendered);
        $this->assertStringContainsString('Last 8 hours', $rendered);
        $this->assertStringNotContainsString('Total Miles', $rendered);
        $this->assertStringContainsString('Zones Covered (last 8 hours)', $rendered);
    }

    #[Test]
    public function rendered_email_empty_state_is_window_aware_when_truncated(): void
    {
        $mail = new EndOfShiftSummary($this->summary([
            'startedAt' => Carbon::parse('2026-07-31 00:00:00', 'UTC'),
            'endedAt' => Carbon::parse('2026-08-17 17:18:50', 'UTC'),
            'windowStartedAt' => Carbon::parse('2026-08-17 09:18:50', 'UTC'),
            'windowEndedAt' => Carbon::parse('2026-08-17 17:18:50', 'UTC'),
            'totalMiles' => 0.0,
            'totalScans' => 0,
            'totalHits' => 0,
            'zones' => [],
        ]));

        $rendered = $mail->render();

        $this->assertStringContainsString('last 8 hours of this shift', $rendered);
        $this->assertStringNotContainsString('during this shift', $rendered);
    }

    #[Test]
    public function rendered_email_flags_missing_driver_email_when_indicated(): void
    {
        $mail = new EndOfShiftSummary($this->summary(['driverEmail' => null]), driverEmailMissing: true);
        $rendered = $mail->render();

        $this->assertStringContainsString('No driver email on file', $rendered);
    }
}
