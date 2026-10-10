<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\RendersDashboardStat;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidTimeZoneException;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Exceptions;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class CompanyMetricsOverviewTest extends TestCase
{
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_stats_renders_a_failed_card_unavailable_and_keeps_its_sibling(): void
    {
        Exceptions::fake();

        $method = new ReflectionMethod(DegradingCompanyMetricsOverview::class, 'getStats');
        $method->setAccessible(true);

        /** @var array<int, Stat> $stats */
        $stats = $method->invoke(new DegradingCompanyMetricsOverview);

        $this->assertCount(2, $stats);
        $this->assertSame('--', $stats[0]->getValue());
        $this->assertSame('Unavailable', $stats[0]->getDescription());
        $this->assertSame('gray', $stats[0]->getColor());
        $this->assertSame('Failing Card', $stats[0]->getLabel());

        $this->assertSame('48', $stats[1]->getValue());
        $this->assertSame('Healthy Card', $stats[1]->getLabel());
        $this->assertTrue(HealthyCompanyMetricCard::$rendered);

        Exceptions::assertReported(function (\RuntimeException $exception): bool {
            return $exception->getMessage() === 'ClickHouse connection refused (Code: 210)';
        });
    }

    /**
     * The grid builds compact cards through toStat() → getCardTotal() → getMonthlyData().
     * An invalid users.dti must render the base monthly path in UTC, not Unavailable.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_invalid_viewer_timezone_renders_the_base_monthly_card_in_utc(): void
    {
        Carbon::setTestNow('2026-09-01 02:00:00');
        Exceptions::fake();

        $this->actingAs($this->createUser(
            attributes: ['dti' => ['timeZone' => 'NotAReal/Zone']],
            role: RoleEnum::ADMIN->value,
        ));

        TimezoneProbeCard::$windows = [];

        $method = new ReflectionMethod(TimezoneProbeOverview::class, 'getStats');
        $method->setAccessible(true);

        /** @var array<int, Stat> $stats */
        $stats = $method->invoke(new TimezoneProbeOverview);

        $this->assertCount(1, $stats);
        $this->assertSame('4', $stats[0]->getValue());
        $this->assertSame('Timezone Probe', $stats[0]->getLabel());
        $this->assertNotSame('--', $stats[0]->getValue());
        $this->assertSame(
            ['2026-09-01 00:00:00', '2026-09-30 23:59:59'],
            TimezoneProbeCard::$windows[array_key_last(TimezoneProbeCard::$windows)],
        );

        Exceptions::assertNotReported(InvalidTimeZoneException::class);

        Carbon::setTestNow();
    }
}

class FailingCompanyMetricCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public function getCardKey(): string
    {
        return 'failing-company-metric';
    }

    public function getHeading(): string
    {
        return 'Failing Card';
    }

    public static function canView(): bool
    {
        return true;
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        throw new \RuntimeException('ClickHouse connection refused (Code: 210)');
    }
}

class HealthyCompanyMetricCard implements DashboardMetricCard
{
    use RendersDashboardStat;

    public static bool $rendered = false;

    public function getCardKey(): string
    {
        return 'healthy-company-metric';
    }

    public function getHeading(): string
    {
        return 'Healthy Card';
    }

    public static function canView(): bool
    {
        return true;
    }

    public function toStat(?string $selectedCard = null): Stat
    {
        self::$rendered = true;

        return $this->dashboardStat(Stat::make($this->getCardLabel(), '48'), $selectedCard);
    }
}

class DegradingCompanyMetricsOverview extends CompanyMetricsOverview
{
    public static function cards(): array
    {
        return [
            'failing-company-metric' => FailingCompanyMetricCard::class,
            'healthy-company-metric' => HealthyCompanyMetricCard::class,
        ];
    }
}

class TimezoneProbeOverview extends CompanyMetricsOverview
{
    public static function cards(): array
    {
        return [
            'timezone-probe' => TimezoneProbeCard::class,
        ];
    }
}

class TimezoneProbeCard extends DashboardWidget
{
    /** @var array<int, array{0: string, 1: string}> */
    public static array $windows = [];

    protected ?string $heading = 'Timezone Probe';

    public function getRgbColor(): array
    {
        return [1, 2, 3];
    }

    protected function getModelQueryForDates(string $startDate, string $endDate): mixed
    {
        self::$windows[] = [$startDate, $endDate];

        return 4;
    }
}
