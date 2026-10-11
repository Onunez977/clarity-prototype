<?php

namespace Tests\Filament\Tables\Filters;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Filament\Pages\Dashboard;
use App\Filament\Tables\Filters\DateTogglesFilter;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class DateTogglesFilterTest extends TestCase
{
    /**
     * Fleet BC aliases and garbage must not throw from the dashboard filters form.
     * The expected zone is whatever {@see ScanPlateStatsBatchQuery::safeTimezone()}
     * returns on this PHP: the alias when it still constructs, the canonical id when
     * it does not, and UTC for garbage.
     *
     * @return array<string, array{0: string}>
     */
    public static function viewerZones(): array
    {
        return [
            'US/Central' => ['US/Central'],
            'US/Eastern' => ['US/Eastern'],
            'US/Pacific' => ['US/Pacific'],
            'Asia/Calcutta' => ['Asia/Calcutta'],
            'garbage' => ['Not/A_Real_Zone'],
        ];
    }

    #[Group('tables')]
    #[Group('filters')]
    #[Group('dashboard')]
    #[DataProvider('viewerZones')]
    public function test_viewer_timezone_and_the_filters_form_do_not_throw(string $zone): void
    {
        $this->actingAs($this->createUser(
            attributes: ['dti' => ['timeZone' => $zone]],
            role: RoleEnum::ADMIN->value,
        ));

        $expected = ScanPlateStatsBatchQuery::safeTimezone($zone);

        $filter = DateTogglesFilter::make('date')
            ->defaultDate('this_month')
            ->bare();

        $this->assertSame($expected, $filter->viewerTimezone());
        new \DateTimeZone($filter->viewerTimezone());

        $components = $filter->getCustomSchema();
        $end = $components[2];
        $this->assertInstanceOf(DateTimePicker::class, $end);
        $this->assertSame($expected, $end->getTimezone());

        $start = new ReflectionMethod(DateTogglesFilter::class, 'resolveStartDate');
        $resolvedStart = $start->invoke($filter, 'today');
        $this->assertInstanceOf(Carbon::class, $resolvedStart);
        $this->assertSame($expected, $resolvedStart->timezoneName);

        $endDate = new ReflectionMethod(DateTogglesFilter::class, 'resolveEndDate');
        $resolvedEnd = $endDate->invoke($filter, 'yesterday');
        $this->assertInstanceOf(Carbon::class, $resolvedEnd);
        $this->assertSame($expected, $resolvedEnd->timezoneName);
    }

    #[Group('tables')]
    #[Group('filters')]
    #[Group('dashboard')]
    #[DataProvider('viewerZones')]
    public function test_dashboard_filters_form_renders_for_the_viewer_zone(string $zone): void
    {
        $this->actingAs($this->createUser(
            attributes: ['dti' => ['timeZone' => $zone]],
            role: RoleEnum::ADMIN->value,
        ));

        Livewire::test(Dashboard::class)->assertOk();
    }
}
