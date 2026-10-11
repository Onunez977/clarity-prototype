<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Otto;

use App\Support\Otto\LatestHitsWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LatestHitsWindowTest extends TestCase
{
    #[DataProvider('bareLatestPrompts')]
    public function test_bare_latest_applies_the_twelve_hour_window(string $prompt): void
    {
        $this->assertTrue(LatestHitsWindow::applies($prompt));
    }

    #[DataProvider('explicitRangePrompts')]
    public function test_an_explicit_time_range_wins_over_latest(string $prompt): void
    {
        $this->assertFalse(LatestHitsWindow::applies($prompt));
    }

    public function test_apply_replaces_a_snapped_preset_with_the_twelve_hour_token(): void
    {
        $args = LatestHitsWindow::applyToToolArguments(
            [
                'plate' => 'ABC1234',
                'time_range' => 'last_30_days',
                'start_date' => '2026-06-16T00:00:00',
                'end_date' => '2026-07-16T23:59:59',
            ],
            'show me the latest hits',
        );

        $this->assertSame('ABC1234', $args['plate']);
        $this->assertSame(LatestHitsWindow::PRESET, $args['time_range']);
        $this->assertArrayNotHasKey('start_date', $args);
        $this->assertArrayNotHasKey('end_date', $args);
    }

    public function test_apply_leaves_arguments_alone_when_latest_does_not_apply(): void
    {
        $original = ['time_range' => 'today'];

        $this->assertSame(
            $original,
            LatestHitsWindow::applyToToolArguments($original, 'latest hits today'),
        );
        $this->assertSame(
            $original,
            LatestHitsWindow::applyToToolArguments($original, 'show me recent hits'),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function bareLatestPrompts(): iterable
    {
        yield 'latest hits' => ['show me the latest hits'];
        yield 'plate' => ['latest hits for ABC1234'];
        yield 'bare word' => ['the latest'];
        yield 'uppercase' => ['LATEST hits'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function explicitRangePrompts(): iterable
    {
        yield 'today' => ['latest hits today'];
        yield 'last 7 days' => ['latest hits in the last 7 days'];
        yield 'yesterday' => ['latest hits yesterday'];
        yield 'duration' => ['latest 6 hours of hits'];
        yield 'calendar date' => ['latest hits on 2026-03-03'];
        yield 'month span' => ['latest hits between May 4 and June 1'];
        yield 'no latest' => ['show me recent hits'];
    }
}
