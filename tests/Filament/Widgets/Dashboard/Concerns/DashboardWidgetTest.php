<?php

namespace Tests\Filament\Widgets\Dashboard\Concerns;

use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Filament\Widgets\Dashboard\KitUsers;
use App\Filament\Widgets\Dashboard\MissedOpportunities;
use App\Filament\Widgets\Dashboard\RecoveryLeader;
use Carbon\Exceptions\InvalidTimeZoneException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class DashboardWidgetTest extends TestCase
{
    use StatHelper;

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_can_render(): void
    {
        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [255, 0, 0];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }
        };

        $adminUser = $this->createUser(role: RoleEnum::ADMIN->value);
        Auth::setUser($adminUser);

        $this->assertTrue($widget::canView());

        $regularUser = $this->createUser(role: RoleEnum::USER->value);
        Auth::setUser($regularUser);

        $this->assertTrue($widget::canView());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_refresh_data_updates_date(): void
    {
        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [255, 0, 0];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }
        };

        $widget->date = 'last_7d';
        $widget->refreshData('today');

        $this->assertEquals('today', $widget->date);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_graph_labels_generates_correct_months(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [255, 0, 0];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }
        };

        $labels = $widget->getGraphLabels();

        $this->assertCount(13, $labels);

        foreach ($labels as $label) {
            $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \'[0-9]{2}$/', $label);
        }
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_description_for_today(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [255, 0, 0];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }

            public function exposeCacheKey(string $dateType, int $orgId): string
            {
                return $this->buildWidgetCacheKey($dateType, $orgId);
            }
        };

        $widget->date = 'today';

        $cacheKey = $widget->exposeCacheKey('today', $user->org_id);
        Cache::put($cacheKey, 42);

        $description = $widget->getDescription();

        $this->assertStringContainsString('Today: 42', $description->toHtml());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_get_description_for_last_7d(): void
    {
        $this->actingAs(
            $user = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [255, 0, 0];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }

            public function exposeCacheKey(string $dateType, int $orgId): string
            {
                return $this->buildWidgetCacheKey($dateType, $orgId);
            }
        };

        $widget->date = 'last_7d';

        $cacheKey = $widget->exposeCacheKey('last_7d', $user->org_id);
        Cache::put($cacheKey, 150);

        $description = $widget->getDescription();

        $this->assertStringContainsString('Last 7 Days: 150', $description->toHtml());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_grid_and_expanded_instances_both_use_the_vendor_chart_widget_view(): void
    {
        Auth::setUser($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [255, 0, 0];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }

            public function exposeView(): string
            {
                return $this->view;
            }
        };

        $widget->filters = [];
        $widget->mount();

        $this->assertSame('filament-widgets::chart-widget', $widget->exposeView());

        $widget->expanded = true;

        $this->assertSame('filament-widgets::chart-widget', $widget->exposeView());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_abstract_get_rgb_color_implementation(): void
    {
        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [128, 64, 192];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }
        };

        $color = $widget->getRgbColor();

        $this->assertIsArray($color);
        $this->assertCount(3, $color);
        $this->assertEquals([128, 64, 192], $color);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_expanded_chart_uses_recovery_leaderboard_height_and_is_not_lazy(): void
    {
        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'Test Widget';

            protected ?string $jobClass = 'TestJob';

            public function getRgbColor(): array
            {
                return [255, 0, 0];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }

            public function exposeMaxHeight(): ?string
            {
                return $this->getMaxHeight();
            }
        };

        $this->assertFalse($widget::isLazy());
        $this->assertFalse(RecoveryLeader::isLazy());
        $this->assertFalse(KitUsers::isLazy());

        $widget->expanded = false;
        $this->assertSame('12rem', $widget->exposeMaxHeight());
        $this->assertSame(['default' => 1, 'lg' => 1], $widget->getColumnSpan());

        $widget->expanded = true;
        $this->assertSame('600px', $widget->exposeMaxHeight());
        $this->assertSame('full', $widget->getColumnSpan());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_to_stat_computes_monthly_data_once(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        $widget = new class extends DashboardWidget
        {
            public int $queryCalls = 0;

            protected ?string $heading = 'Memo Widget';

            public function getRgbColor(): array
            {
                return [1, 2, 3];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                $this->queryCalls++;

                return 4;
            }
        };

        $widget->date = 'this_month';
        $widget->toStat();

        $this->assertSame(13, $widget->queryCalls);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_impersonation_cache_ttl_is_ten_seconds(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));
        session()->put('admin_impersonator', true);

        $widget = new class extends DashboardWidget
        {
            protected ?string $heading = 'TTL Widget';

            public function getRgbColor(): array
            {
                return [1, 2, 3];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                return 0;
            }

            public function exposeCacheTTL(int $index = 0): \DateTimeInterface
            {
                return $this->getCacheTTL($index);
            }
        };

        $this->assertEqualsWithDelta(10, now()->diffInSeconds($widget->exposeCacheTTL(0)), 1);
        $this->assertEqualsWithDelta(10, now()->diffInSeconds($widget->exposeCacheTTL(1)), 1);
    }

    /**
     * Company Metrics calls DashboardWidget::getMonthlyData() through toStat().
     * Subclasses that already sanitise never reach that line. An invalid users.dti
     * must fall back to UTC here, on the base class, without InvalidTimeZoneException.
     */
    #[Group('widgets')]
    #[Group('dashboard')]
    public function test_invalid_viewer_timezone_monthly_path_falls_back_to_utc(): void
    {
        Carbon::setTestNow('2026-09-01 02:00:00');

        $this->actingAs($this->createUser(
            attributes: ['dti' => ['timeZone' => 'NotAReal/Zone']],
            role: RoleEnum::ADMIN->value,
        ));

        $this->assertSame('NotAReal/Zone', auth()->user()->timezone);

        $widget = new class extends DashboardWidget
        {
            /** @var array<int, array{0: string, 1: string}> */
            public array $windows = [];

            protected ?string $heading = 'Timezone Probe';

            public function getRgbColor(): array
            {
                return [1, 2, 3];
            }

            protected function getModelQueryForDates(string $startDate, string $endDate): mixed
            {
                $this->windows[] = [$startDate, $endDate];

                return 4;
            }
        };

        $widget->date = 'this_month';

        $stat = $widget->toStat();

        $this->assertSame('4', $stat->getValue());
        $this->assertSame("Sep '26", $widget->getGraphLabels()[12]);
        $this->assertSame(
            ['2026-09-01 00:00:00', '2026-09-30 23:59:59'],
            $widget->windows[array_key_last($widget->windows)],
        );

        [$headlineStart, $headlineEnd] = (new ReflectionMethod($widget, 'headlineWindow'))->invoke($widget);
        $this->assertSame('2026-09-01 00:00:00', $headlineStart);
        $this->assertSame('2026-09-30 23:59:59', $headlineEnd);

        $widget->date = 'today';
        [$start, $end] = (new ReflectionMethod($widget, 'resolveCardRange'))->invoke($widget);
        $this->assertSame('UTC', $start->timezoneName);
        $this->assertSame('2026-09-01 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-09-01 23:59:59', $end->toDateTimeString());

        $missed = new MissedOpportunities;
        $missed->date = 'this_month';

        try {
            $missed->toStat();
        } catch (InvalidTimeZoneException $exception) {
            $this->fail($exception->getMessage());
        } catch (\Throwable) {
            // The query may fail without ClickHouse. The timezone exception is the contract.
        }

        Carbon::setTestNow();
    }
}
