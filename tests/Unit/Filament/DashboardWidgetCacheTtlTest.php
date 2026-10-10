<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class DashboardWidgetCacheTtlTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('widgets')]
    public function test_closed_month_settle_window_and_impersonation_order(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $widget = new CacheTtlProbeWidget;
        $yesterday = now()->subDay();

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $this->assertSame(
            now()->addSeconds(10)->getTimestamp(),
            $widget->ttl(1, $yesterday)->getTimestamp()
        );

        session()->forget('admin_impersonator');

        $this->assertSame(now()->addMinutes(10)->getTimestamp(), $widget->ttl(0)->getTimestamp());
        $this->assertSame(now()->addMinutes(10)->getTimestamp(), $widget->ttl(1, now()->subHour())->getTimestamp());
        $this->assertSame(now()->addMinutes(10)->getTimestamp(), $widget->ttl(1, now()->subDays(3))->getTimestamp());
        $this->assertSame(now()->addYear()->getTimestamp(), $widget->ttl(1, now()->subDays(9))->getTimestamp());
        $this->assertSame(
            now()->addYear()->getTimestamp(),
            $widget->ttl(1, now()->subSeconds(DashboardWidget::CLOSED_MONTH_SETTLE_FULL_SECONDS))->getTimestamp()
        );
        $this->assertSame(
            now()->addMinutes(10)->getTimestamp(),
            $widget->ttl(1, now()->subSeconds(DashboardWidget::CLOSED_MONTH_SETTLE_FULL_SECONDS - 1))->getTimestamp()
        );
        $this->assertSame(now()->addYear()->getTimestamp(), $widget->ttl(1)->getTimestamp());
    }
}

class CacheTtlProbeWidget extends DashboardWidget
{
    public function ttl(int $index = 0, ?\DateTimeInterface $monthEndedAt = null): \DateTimeInterface
    {
        return $this->getCacheTTL($index, $monthEndedAt);
    }

    public function getRgbColor(): array
    {
        return [0, 0, 0];
    }

    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return 0;
    }
}
