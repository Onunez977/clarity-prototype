<?php

declare(strict_types=1);

namespace Tests\Feature\ClarityScout;

use App\Http\Controllers\Api\ClarityScoutDashboardController;
use App\Http\Controllers\Api\Concerns\ScoutMapHelpers;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DEV-2306: pins the backend date-window contract for the standardized Scout
 * range set (today/yesterday/last_7/last_15/last_30) on BOTH date-range
 * resolvers — ScoutMapHelpers::getDateRange (admin/driver map surfaces) and
 * ClarityScoutDashboardController::getDateRange (driver dashboard). Guards
 * against a future edit silently dropping a range either surface now needs.
 */
#[Group('clarity-scout')]
class ScoutDateRangeStandardizationTest extends TestCase
{
    private const TIMEZONE = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', self::TIMEZONE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function standardizedRangesProvider(): array
    {
        return [
            'today' => ['today'],
            'yesterday' => ['yesterday'],
            'last_7' => ['last_7'],
            'last_15' => ['last_15'],
            'last_30' => ['last_30'],
        ];
    }

    #[DataProvider('standardizedRangesProvider')]
    public function test_map_helpers_resolves_the_standardized_range(string $range): void
    {
        [$start, $end] = $this->invokeTraitGetDateRange($range);

        [$expectedStart, $expectedEnd] = $this->expectedWindow($range);

        $this->assertSame($expectedStart->toDateTimeString(), $start->toDateTimeString());
        $this->assertSame($expectedEnd->toDateTimeString(), $end->toDateTimeString());
    }

    #[DataProvider('standardizedRangesProvider')]
    public function test_dashboard_controller_resolves_the_standardized_range(string $range): void
    {
        [$start, $end] = $this->invokeControllerGetDateRange($range);

        [$expectedStart, $expectedEnd] = $this->expectedWindow($range);

        $this->assertSame($expectedStart->toDateTimeString(), $start->toDateTimeString());
        $this->assertSame($expectedEnd->toDateTimeString(), $end->toDateTimeString());
    }

    public function test_map_helpers_collapses_an_unknown_range_to_today(): void
    {
        [$start, $end] = $this->invokeTraitGetDateRange('garbage');

        $now = Carbon::now(self::TIMEZONE);

        $this->assertSame($now->copy()->startOfDay()->toDateTimeString(), $start->toDateTimeString());
        $this->assertSame($now->copy()->endOfDay()->toDateTimeString(), $end->toDateTimeString());
    }

    public function test_dashboard_controller_collapses_an_unknown_range_to_today(): void
    {
        [$start, $end] = $this->invokeControllerGetDateRange('garbage');

        $now = Carbon::now(self::TIMEZONE);

        $this->assertSame($now->copy()->startOfDay()->toDateTimeString(), $start->toDateTimeString());
        $this->assertSame($now->copy()->endOfDay()->toDateTimeString(), $end->toDateTimeString());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function expectedWindow(string $range): array
    {
        $now = Carbon::now(self::TIMEZONE);

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'last_15' => [$now->copy()->subDays(15)->startOfDay(), $now->copy()->endOfDay()],
            'last_30' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function invokeTraitGetDateRange(string $range): array
    {
        $instance = new class
        {
            use ScoutMapHelpers;

            public function callGetDateRange(string $range, string $timezone): array
            {
                return $this->getDateRange($range, $timezone);
            }
        };

        return $instance->callGetDateRange($range, self::TIMEZONE);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function invokeControllerGetDateRange(string $range): array
    {
        $controller = new ClarityScoutDashboardController;

        $method = new ReflectionMethod($controller, 'getDateRange');
        $method->setAccessible(true);

        return $method->invoke($controller, $range, self::TIMEZONE);
    }
}
