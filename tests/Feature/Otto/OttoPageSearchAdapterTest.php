<?php

namespace Tests\Feature\Otto;

use App\Ai\Tools\ApplyHitSearchFiltersTool;
use App\Ai\Tools\ApplyHotlistSearchFiltersTool;
use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Filament\Resources\HitsResource\Pages\ListHits;
use App\Filament\Resources\HotlistResource\Pages\ListHotlists;
use App\Filament\Resources\ScansResource\Pages\ListScans;
use App\Support\Otto\OttoAccess;
use Carbon\Carbon;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use Tests\TestCase;

class OttoPageSearchAdapterTest extends TestCase
{
    /**
     * @param  list<string>|string  $permissions
     */
    private function actingOttoUser(array|string $permissions): void
    {
        $user = $this->createUser(permissions: array_values(array_unique([
            ...(array) $permissions,
            OttoAccess::PERMISSION,
        ])));
        $this->setOrgProTier($user->org);
        $this->actingAs($user->fresh()->load('org.meta'));
    }

    public function test_an_accepted_scan_filter_action_sets_the_page_filters(): void
    {
        $this->actingOttoUser(PermissionEnum::ViewAny_SCAN->value);

        Livewire::test(ListScans::class)
            ->dispatch(
                'otto-page-action',
                pageType: ApplyScanSearchFiltersTool::PAGE_TYPE,
                name: ApplyScanSearchFiltersTool::ACTION,
                payload: ['plate' => 'ABC1234', 'time_range' => 'last_7d'],
            )
            ->assertSet('searchApplied', true)
            ->assertSet('tableFilters', fn (array $filters): bool => data_get($filters, 'filters.ocr') === 'ABC1234'
                && data_get($filters, 'scanned_at.date_toggles') === 'last_7d');
    }

    public function test_an_accepted_custom_date_range_sets_scan_start_and_end(): void
    {
        $user = $this->createUser([
            'dti' => ['timeZone' => 'America/New_York'],
        ], permissions: [
            PermissionEnum::ViewAny_SCAN->value,
            OttoAccess::PERMISSION,
        ]);
        $this->setOrgProTier($user->org);
        $this->actingAs($user->fresh()->load('org.meta'));

        Livewire::test(ListScans::class)
            ->dispatch(
                'otto-page-action',
                pageType: ApplyScanSearchFiltersTool::PAGE_TYPE,
                name: ApplyScanSearchFiltersTool::ACTION,
                payload: [
                    'time_range' => [
                        'start' => '2026-05-04T21:00:00-07:00',
                        'end' => '2026-07-01T09:44:00-07:00',
                    ],
                ],
            )
            ->assertSet('searchApplied', true)
            ->assertSet('tableFilters', function (array $filters): bool {
                $start = data_get($filters, 'scanned_at.start');
                $end = data_get($filters, 'scanned_at.end');

                return is_string($start) && is_string($end)
                    && Carbon::parse($start, 'America/New_York')->equalTo(Carbon::parse('2026-05-04T21:00:00-07:00')->timezone('America/New_York'))
                    && Carbon::parse($end, 'America/New_York')->equalTo(Carbon::parse('2026-07-01T09:44:00-07:00')->timezone('America/New_York'));
            });
    }

    public function test_it_clears_the_scan_plate_when_the_payload_carries_an_empty_value(): void
    {
        $this->actingOttoUser(PermissionEnum::ViewAny_SCAN->value);

        Livewire::test(ListScans::class)
            ->set('tableFilters.filters.ocr', 'XYZ789')
            ->dispatch(
                'otto-page-action',
                pageType: ApplyScanSearchFiltersTool::PAGE_TYPE,
                name: ApplyScanSearchFiltersTool::ACTION,
                payload: ['plate' => ''],
            )
            ->assertSet('tableFilters', fn (array $filters): bool => data_get($filters, 'filters.ocr') === '');
    }

    public function test_it_ignores_a_scan_action_meant_for_another_page(): void
    {
        $this->actingOttoUser(PermissionEnum::ViewAny_SCAN->value);

        Livewire::test(ListScans::class)
            ->dispatch(
                'otto-page-action',
                pageType: ApplyHitSearchFiltersTool::PAGE_TYPE,
                name: ApplyScanSearchFiltersTool::ACTION,
                payload: ['plate' => 'ABC1234'],
            )
            ->assertSet('searchApplied', false)
            ->assertSet('tableFilters', fn (array $filters): bool => blank(data_get($filters, 'filters.ocr')));
    }

    public function test_an_accepted_hit_filter_action_sets_plate_and_both_date_filters(): void
    {
        $this->actingOttoUser(PermissionEnum::ViewAny_Hit->value);

        Livewire::test(ListHits::class)
            ->dispatch(
                'otto-page-action',
                pageType: ApplyHitSearchFiltersTool::PAGE_TYPE,
                name: ApplyHitSearchFiltersTool::ACTION,
                payload: ['plate' => 'HIT123', 'time_range' => 'last_30d'],
            )
            ->assertSet('tableFilters', fn (array $filters): bool => data_get($filters, 'filters.scan.ocr') === 'HIT123'
                && data_get($filters, 'hits.created_at.date_toggles') === 'last_30d'
                && data_get($filters, 'scans.scanned_at.date_toggles') === 'last_30d');
    }

    public function test_an_accepted_hotlist_filter_action_sets_plate_and_vin(): void
    {
        $this->actingOttoUser(PermissionEnum::ViewAny_Hotlist->value);

        Livewire::test(ListHotlists::class)
            ->dispatch(
                'otto-page-action',
                pageType: ApplyHotlistSearchFiltersTool::PAGE_TYPE,
                name: ApplyHotlistSearchFiltersTool::ACTION,
                payload: ['plate' => 'ASN123', 'vin' => '1HGCM', 'flex_search' => false],
            )
            ->assertSet('tableFilters', fn (array $filters): bool => data_get($filters, 'filters.plate') === 'ASN123'
                && data_get($filters, 'filters.vin') === '1HGCM');
    }
}
