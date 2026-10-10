<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools\Concerns;

use App\Ai\Tools\Concerns\ResolvesOttoDateRange;
use App\Models\Investigation;
use Carbon\Carbon;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ResolvesOttoDateRangeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_last_90_days_shortcut_ends_at_end_of_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 15:30:00', 'UTC'));

        [$start, $end] = $this->resolver()->resolve(new Request(['time_range' => 'last_90_days']), 'UTC');

        $this->assertUtcInstant('2026-05-27 00:00:00', $start);
        $this->assertUtcInstant('2026-08-25 23:59:59', $end);
    }

    public function test_date_only_end_date_expands_to_end_of_day(): void
    {
        [$start, $end] = $this->resolver()->resolve(new Request([
            'start_date' => '2026-05-27',
            'end_date' => '2026-08-25',
        ]), 'UTC');

        $this->assertUtcInstant('2026-05-27 00:00:00', $start);
        $this->assertUtcInstant('2026-08-25 23:59:59', $end);
    }

    public function test_datetime_end_date_keeps_explicit_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 18:00:00', 'UTC'));

        [$start, $end] = $this->resolver()->resolve(new Request([
            'start_date' => '2026-05-27 08:15:00',
            'end_date' => '2026-08-25 16:45:00',
        ]), 'UTC');

        $this->assertUtcInstant('2026-05-27 08:15:00', $start);
        $this->assertUtcInstant('2026-08-25 16:45:00', $end);
    }

    public function test_naive_datetimes_are_interpreted_in_the_users_timezone(): void
    {
        [$start, $end] = $this->resolver()->resolve(new Request([
            'start_date' => '2026-05-26T18:43:00',
            'end_date' => '2026-06-12T15:00:00',
        ]), 'America/Chicago');

        $this->assertUtcInstant('2026-05-26 23:43:00', $start);
        $this->assertUtcInstant('2026-06-12 20:00:00', $end);
    }

    public function test_explicit_timezone_offset_wins_over_profile_timezone(): void
    {
        [$start] = $this->resolver()->resolve(new Request([
            'start_date' => '2026-05-26T18:43:00-05:00',
            'end_date' => '2026-06-12T15:00:00-05:00',
        ]), 'America/New_York');

        $this->assertUtcInstant('2026-05-26 23:43:00', $start);
    }

    public function test_reversed_range_is_ordered_earliest_first(): void
    {
        [$start, $end] = $this->resolver()->resolve(new Request([
            'start_date' => '2026-06-12T15:00:00',
            'end_date' => '2026-05-26T18:43:00',
        ]), 'America/Chicago');

        $this->assertTrue(Carbon::parse($start)->lessThan(Carbon::parse($end)));
        $this->assertUtcInstant('2026-05-26 23:43:00', $start);
        $this->assertUtcInstant('2026-06-12 20:00:00', $end);
    }

    public function test_today_uses_the_users_calendar_day_not_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 03:00:00', 'UTC'));

        [$start, $end] = $this->resolver()->resolve(new Request(['time_range' => 'today']), 'America/New_York');

        $this->assertUtcInstant('2026-07-15 04:00:00', $start);
        $this->assertUtcInstant('2026-07-16 03:59:59', $end);
    }

    public function test_date_only_values_expand_in_the_users_timezone(): void
    {
        [$start, $end] = $this->resolver()->resolve(new Request([
            'start_date' => '2026-05-27',
            'end_date' => '2026-08-25',
        ]), 'America/Chicago');

        $this->assertUtcInstant('2026-05-27 05:00:00', $start);
        $this->assertUtcInstant('2026-08-26 04:59:59', $end);
    }

    public function test_prompt_last_six_days_overrides_snapped_last_7_days_preset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'America/New_York'));

        $investigation = new Investigation;
        $investigation->setActivePrompt('scans for LLB9130 on all cameras in last 6 days');

        [$start, $end] = $this->resolver($investigation)->resolve(
            new Request(['time_range' => 'last_7_days']),
            'America/New_York',
        );

        $this->assertSame('2026-07-10 15:00:00', Carbon::parse($start)->timezone('America/New_York')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-16 15:00:00', Carbon::parse($end)->timezone('America/New_York')->format('Y-m-d H:i:s'));
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $start);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $end);
    }

    public function test_bare_latest_stays_on_the_thirty_day_default_until_a_hit_tool_opts_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'UTC'));

        $investigation = new Investigation;
        $investigation->setActivePrompt('show me the latest hits');

        [$start] = $this->resolver($investigation)->resolve(
            new Request(['time_range' => 'last_30_days']),
            'UTC',
        );
        $this->assertUtcInstant('2026-06-16 00:00:00', $start);

        [$hitStart, $hitEnd] = $this->hitResolver($investigation)->resolve(
            new Request(['time_range' => 'last_30_days']),
            'UTC',
        );
        $this->assertUtcInstant('2026-07-16 03:00:00', $hitStart);
        $this->assertUtcInstant('2026-07-16 15:00:00', $hitEnd);
    }

    public function test_latest_with_today_keeps_today_for_hit_tools(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'UTC'));

        $investigation = new Investigation;
        $investigation->setActivePrompt('latest hits today');

        [$start, $end] = $this->hitResolver($investigation)->resolve(
            new Request(['time_range' => 'last_30_days']),
            'UTC',
        );

        $this->assertUtcInstant('2026-07-16 00:00:00', $start);
        $this->assertUtcInstant('2026-07-16 23:59:59', $end);
    }

    private function assertUtcInstant(string $expectedUtc, ?string $iso): void
    {
        $this->assertNotNull($iso);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $iso);
        $this->assertSame($expectedUtc, Carbon::parse($iso)->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * @return object{resolve: callable(Request, string): array{0: ?string, 1: ?string}}
     */
    private function resolver(?Investigation $investigation = null): object
    {
        return new class($investigation)
        {
            use ResolvesOttoDateRange;

            public function __construct(public ?Investigation $investigation) {}

            /**
             * @return array{0: ?string, 1: ?string}
             */
            public function resolve(Request $request, string $tz): array
            {
                return $this->resolveOttoRange($request, $tz);
            }
        };
    }

    /**
     * @return object{resolve: callable(Request, string): array{0: ?string, 1: ?string}}
     */
    private function hitResolver(Investigation $investigation): object
    {
        return new class($investigation)
        {
            use ResolvesOttoDateRange;

            public function __construct(public ?Investigation $investigation) {}

            protected function honorsLatestHitsWindow(): bool
            {
                return true;
            }

            /**
             * @return array{0: ?string, 1: ?string}
             */
            public function resolve(Request $request, string $tz): array
            {
                return $this->resolveOttoRange($request, $tz);
            }
        };
    }
}
