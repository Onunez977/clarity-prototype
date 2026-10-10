<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Otto;

use App\Support\Otto\UserRequestedTimeRange;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserRequestedTimeRangeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('exactPresetPrompts')]
    public function test_exact_preset_windows_map_to_presets(string $prompt, string $preset): void
    {
        $parsed = UserRequestedTimeRange::parse($prompt);

        $this->assertNotNull($parsed);
        $this->assertSame($preset, $parsed->preset);
    }

    #[DataProvider('customRelativePrompts')]
    public function test_non_preset_relative_windows_are_custom(string $prompt, int $amount, string $unit): void
    {
        $parsed = UserRequestedTimeRange::parse($prompt);

        $this->assertNotNull($parsed);
        $this->assertNull($parsed->preset);
        $this->assertSame($amount, $parsed->amount);
        $this->assertSame($unit, $parsed->unit);
    }

    public function test_apply_to_tool_arguments_replaces_snapped_preset_with_custom_bounds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'America/New_York'));

        $args = UserRequestedTimeRange::applyToToolArguments(
            ['plate' => 'LLB9130', 'time_range' => 'last_7_days'],
            'scans for LLB9130 on all cameras in last 6 days',
            'America/New_York',
        );

        $this->assertArrayNotHasKey('time_range', $args);
        $this->assertSame('LLB9130', $args['plate']);

        $start = Carbon::parse($args['start_date'])->timezone('America/New_York');
        $end = Carbon::parse($args['end_date'])->timezone('America/New_York');

        $this->assertSame('2026-07-10 15:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-16 15:00:00', $end->format('Y-m-d H:i:s'));
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $args['start_date']);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $args['end_date']);
    }

    public function test_apply_to_tool_arguments_keeps_exact_preset(): void
    {
        $args = UserRequestedTimeRange::applyToToolArguments(
            ['time_range' => 'last_30_days', 'start_date' => 'ignored', 'end_date' => 'ignored'],
            'search for ABC123 in the last 7 days',
            'UTC',
        );

        $this->assertSame('last_7_days', $args['time_range']);
        $this->assertArrayNotHasKey('start_date', $args);
        $this->assertArrayNotHasKey('end_date', $args);
    }

    public function test_apply_to_tool_arguments_is_noop_without_time_cue(): void
    {
        $original = ['plate' => 'ABC123', 'time_range' => 'last_30_days'];

        $this->assertSame(
            $original,
            UserRequestedTimeRange::applyToToolArguments(
                $original,
                'search for plate ABC123 on all cameras',
                'UTC',
            ),
        );
    }

    #[DataProvider('yesterdayThroughTodayPrompts')]
    public function test_yesterday_through_today_is_custom_not_today_preset(string $prompt): void
    {
        $parsed = UserRequestedTimeRange::parse($prompt);

        $this->assertNotNull($parsed);
        $this->assertNull($parsed->preset);
        $this->assertSame('yesterday_through_today', $parsed->unit);
    }

    public function test_apply_preserves_mixed_yesterday_through_today_bounds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'America/New_York'));

        $args = UserRequestedTimeRange::applyToToolArguments(
            [
                'plate' => 'LLB9130',
                'time_range' => 'today',
                'start_date' => '2026-07-15T00:00:00-04:00',
                'end_date' => '2026-07-16T15:00:00-04:00',
            ],
            'scans for LLB9130 yesterday through today',
            'America/New_York',
        );

        $this->assertArrayNotHasKey('time_range', $args);
        $start = Carbon::parse($args['start_date'])->timezone('America/New_York');
        $end = Carbon::parse($args['end_date'])->timezone('America/New_York');

        $this->assertSame('2026-07-15 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-16 15:00:00', $end->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function exactPresetPrompts(): iterable
    {
        yield 'today' => ['find white Toyotas today', 'today'];
        yield 'last 7 days' => ['scans in the last 7 days', 'last_7_days'];
        yield 'past 30 days' => ['hits over the past 30 days', 'last_30_days'];
        yield 'last 90 days' => ['in the last 90 days', 'last_90_days'];
        yield 'last week' => ['show scans from last week', 'last_7_days'];
        yield 'past month' => ['hits in the past month', 'last_30_days'];
        yield 'last one week' => ['last one week of scans', 'last_7_days'];
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function customRelativePrompts(): iterable
    {
        yield 'last 6 days' => ['scans for LLB9130 on all cameras in last 6 days', 6, 'days'];
        yield 'last 4 days' => ['green honda SUVs in the last 4 days', 4, 'days'];
        yield 'last six days' => ['in the last six days', 6, 'days'];
        yield 'past 2 hours' => ['past 2 hours', 2, 'hours'];
        yield 'last 36 hours' => ['over the last 36 hours', 36, 'hours'];
        yield 'last 3 weeks' => ['last 3 weeks', 3, 'weeks'];
        yield 'last 6 months' => ['last 6 months', 6, 'months'];
        yield 'last 60 days' => ['last 60 days', 60, 'days'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function yesterdayThroughTodayPrompts(): iterable
    {
        yield 'through' => ['scans yesterday through today'];
        yield 'from to' => ['from yesterday to today'];
        yield 'and' => ['hits yesterday and today'];
        yield 'between' => ['between yesterday and today'];
    }
}
