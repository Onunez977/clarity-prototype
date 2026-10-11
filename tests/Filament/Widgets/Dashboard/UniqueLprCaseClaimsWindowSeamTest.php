<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\UniqueLprCaseClaims;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * DEV-2463, review round 3 lane 1 M1. The today/last_7d seam produces the headline for 2 of
 * the 4 filter states and was pinned by NOTHING: every existing double overrode
 * fetchWindowTotal() wholesale, and the one double that inherits it is only ever driven with
 * date='this_month', which never reaches currentPeriodTotal().
 *
 * Two mutants survived all 35 tests before this file existed:
 *   (a) ['deduped'] -> ['successful'] in fetchWindowTotal() -- the card would render the
 *       funnel stage the ticket's Q1 explicitly REJECTED (org 165 Jul 2026: 9,262 vs 7,230,
 *       ~28% overstated). The monthly seam's stage selection IS pinned; this one was not.
 *   (b) dropping the timezone from Carbon::parse() -- resolveCardRange() hands naive strings
 *       in the VIEWER's timezone, so parsing them as app.timezone (UTC) shifts a Chicago
 *       viewer's "Today" by 5-6h, dropping the last hours of real claims.
 */
final class UniqueLprCaseClaimsWindowSeamTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Mutant (a): the window seam must read `deduped`, not `all_claims` or `successful`. */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_the_window_seam_renders_deduped_not_another_funnel_stage(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new StageProbingWindowWidget;
        $widget->date = 'today';
        // Three distinguishable stage values so a wrong pick is unambiguous.
        $widget->row = ['all_claims' => 900, 'successful' => 500, 'deduped' => 111];

        $this->assertSame(
            111,
            $this->invokeWindow($widget),
            'the card must render the deduped (billed) stage -- 500 would be `successful`, 900 `all_claims`'
        );
    }

    /**
     * Mutant (b): resolveCardRange() returns naive strings in the VIEWER's timezone, so they
     * must be re-anchored to it. Frozen at an instant where UTC and Chicago disagree on the
     * date, a UTC-parsed window is 5h off.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('unique-lpr-case-claims')]
    public function test_the_window_bounds_are_anchored_to_the_viewers_timezone(): void
    {
        // 03:00 UTC is 22:00 the PREVIOUS day in America/Chicago.
        Carbon::setTestNow('2026-08-15 03:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new BoundsCapturingWindowWidget;
        $widget->date = 'today';
        $this->invokeWindow($widget);

        $this->assertNotNull($widget->start, 'the seam must have been reached');

        // The viewer's "today" is Aug 14 local = 05:00 Aug 14 UTC -> 04:59:59 Aug 15 UTC.
        // A UTC-parsed window would be 00:00 Aug 14 -> 23:59:59 Aug 14 UTC instead.
        $this->assertSame(
            '2026-08-14 05:00:00',
            $widget->start->clone()->utc()->toDateTimeString(),
            'a viewer-local day must convert to a UTC-offset window, not a naive UTC midnight'
        );
        $this->assertSame(
            '2026-08-15 04:59:59',
            $widget->end->clone()->utc()->toDateTimeString(),
            'dropping the timezone from Carbon::parse() shifts this window by the viewer offset'
        );
    }

    private function invokeWindow(UniqueLprCaseClaims $widget): int
    {
        $m = new \ReflectionMethod($widget, 'currentPeriodTotal');
        $m->setAccessible(true);

        return $m->invoke($widget);
    }
}

/**
 * Stubs the QUERY, not the seam -- so fetchWindowTotal()'s own ['deduped'] selection is what
 * is under test. Overriding fetchWindowTotal (as every other double does) is exactly why this
 * mutant survived.
 */
class StageProbingWindowWidget extends UniqueLprCaseClaims
{
    /** @var array{all_claims: int, successful: int, deduped: int} */
    public array $row = [];

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return [];
    }

    protected function runWindowQuery(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->row;
    }
}

/** Captures the Carbon bounds fetchWindowTotal() is handed. */
class BoundsCapturingWindowWidget extends UniqueLprCaseClaims
{
    public ?CarbonInterface $start = null;

    public ?CarbonInterface $end = null;

    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return [];
    }

    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        $this->start = $start;
        $this->end = $end;

        return 0;
    }
}
