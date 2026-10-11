<?php

namespace Tests\Filament\Resources;

use App\Actions\Activity\Log\HitsSearch;
use App\Enums\DispositionEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Resources\HitsResource\Pages\ListHits;
use App\Filament\Widgets\Hits\HitMetricCards;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use Clickbar\Magellan\Data\Geometries\Point;
use Filament\Tables\Table;
use InsightLPR\BlackAdam\Enum\HitType;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Features\SupportReactiveProps\SupportReactiveProps;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\ResourceTestCase;

class HitsResourceTest extends ResourceTestCase
{
    protected mixed $userPermissions = PermissionEnum::ViewAny_Hit->value;

    private function fakeHits(array $hotlistAttributes = [], array $scanAttributes = []): array
    {
        $this->assertEquals(0, Hit::count());

        $kit = Kit::factory()
            ->forOrg($this->user->org)
            ->create();

        // LIVE HITS
        Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) use ($kit) {
                Scan::factory()
                    ->for($kit)
                    ->create([
                        'ocr' => $hotlist->plate,
                    ]);
            })
            ->create([
                'org_id' => $this->user->org_id,
            ]);

        $otherHits = Hit::all();
        $this->assertCount(1, $otherHits);

        // HISTORICAL HIT
        Scan::factory()
            ->for($kit)
            ->afterCreating(function (Scan $scan) use ($hotlistAttributes) {
                Hotlist::factory()
                    ->create(\array_merge($hotlistAttributes, [
                        'plate' => $scan->ocr,
                        'org_id' => $this->user->org_id,
                    ]));
            })
            ->create($scanAttributes);

        $this->assertEquals(2, Hit::count());

        return [
            $otherHits,
            Hit::query()
                ->whereNotIn('id', $otherHits->pluck('id'))
                ->first(),
        ];
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_can_render_table(): void
    {
        $this->assertEquals(0, Hit::count());

        $kit = Kit::factory()
            ->forOrg($this->user->org)
            ->create();

        Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) use ($kit) {
                Scan::factory()
                    ->for($kit)
                    ->create([
                        'ocr' => $hotlist->plate,
                    ]);
            })
            ->create([
                'org_id' => $this->user->org_id,
            ]);

        $hits = Hit::all();

        $this->assertCount(1, $hits);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($hits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(ListHits::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_can_render_table_columns(): void
    {
        $this->assertEquals(0, Hit::count());

        $kit = Kit::factory()
            ->forOrg($this->user->org)
            ->create();

        $hotlist = Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) use ($kit) {
                Scan::factory()
                    ->for($kit)
                    ->create([
                        'ocr' => $hotlist->plate,
                    ]);
            })
            ->create([
                'org_id' => $this->user->org_id,
            ]);

        $this->assertEquals(1, Hit::count());

        $hit = Hit::first();

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords([$hit])
            ->assertCanRenderTableColumn('scans_ocr')
            ->assertSeeText($hit->scan->ocr)
            // ->assertCanRenderTableColumn('hotlist_plate')
            // ->assertSeeText($hotlist->plate)
            ->assertSeeText($hit->type)
            ->assertCanRenderTableColumn('hotlist_status')
            ->assertSeeText($hotlist->status)
            ->assertCanRenderTableColumn('scan_metadata')
            ->assertCanRenderTableColumn('created_at')
            ->assertCanRenderTableColumn('scans_scanned_at')
            ->assertCanRenderTableColumn('scans_geo')
            ->assertSeeText('Lat/Long: '.\implode(', ', $hit->scan->coordinates))
            ->assertCanRenderTableColumn('hotlist_vin')
            ->assertSeeText($hotlist->vin)
            ->assertSeeText(\trim($hotlist->vehicle_year.' '.$hotlist->vehicle_make.' '.$hotlist->vehicle_model))
            // ->assertCanRenderTableColumn('kit_name')
            // ->assertSeeText($kit->name)
            ->assertCanRenderTableColumn('hotlist_remote_order_id')
            ->assertSeeText($hotlist->remote_order_id)
            ->assertCanRenderTableColumn('client_org_name');
        // ->assertCanRenderTableColumn('lienholder_org_name')
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_type_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        $this->assertNotEquals($hit->hit_type, $otherHits->first()->hit_type);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]))
            ->set('tableFilters.filters.hit_type', $hit->hit_type)
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords($otherHits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_all_hit_type_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        // STAGING HISTORICAL HIT
        Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) {
                Scan::factory()
                    ->forOrg($this->user->org)
                    ->create([
                        'ocr' => $hotlist->plate,
                    ]);
            })
            ->create([
                'org_id' => $this->user->org_id,
            ]);

        $stagingHistoricalHit = Hit::query()
            ->whereNotIn('id', $otherHits->merge([$hit])->pluck('id'))
            ->first();

        $stagingHistoricalHit->update([
            'hit_type' => HitTypeEnum::Staging_Historical->value,
        ]);

        // STAGING LIVE HIT
        Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) {
                Scan::factory()
                    ->forOrg($this->user->org)
                    ->create([
                        'ocr' => $hotlist->plate,
                    ]);
            })
            ->create([
                'org_id' => $this->user->org_id,
            ]);

        $stagingLiveHit = Hit::query()
            ->whereNotIn('id', $otherHits->merge([$hit, $stagingHistoricalHit])->pluck('id'))
            ->first();

        $stagingLiveHit->update([
            'hit_type' => HitTypeEnum::Staging_Live->value,
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit, $stagingHistoricalHit, $stagingLiveHit]));
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_staging_hit_type_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        // Make this a STAGING HIT from another Org.
        $hit->update([
            'hit_type' => HitTypeEnum::Staging_Historical->value,
        ]);

        $hit->hotlist->update([
            'org_id' => Org::factory()->create()->id,
        ]);

        $hit->refresh();

        $this->assertNotEquals($hit->hit_type, $otherHits->first()->hit_type);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]))
            ->set('tableFilters.filters.hit_type', $otherHits->first()->hit_type)
            ->assertCanNotSeeTableRecords([$hit])
            ->assertCanSeeTableRecords($otherHits)
            ->set('tableFilters.filters.hit_type', $hit->hit_type)
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords($otherHits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_status_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        $hit->hotlist->update([
            'status' => HotlistStatusEnum::Other,
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits)
            // Initially hidden by starting filter.
            ->assertCanNotSeeTableRecords([$hit])
            ->set('tableFilters.filters.status', $hit->hotlist->status)
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords($otherHits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_plate_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]))
            ->set('tableFilters.filters.scan.ocr', $hit->scan->ocr)
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords($otherHits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_vin_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits(hotlistAttributes: [
            'vin' => '123456',
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]))
            ->set('tableFilters.filters.hotlist.vin', '123456')
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords($otherHits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_remote_order_id_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits(hotlistAttributes: [
            'remote_order_id' => '123456',
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]))
            ->set('tableFilters.filters.hotlist.remote_order_id', '123456')
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords($otherHits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_coordinates_address_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits(scanAttributes: [
            'geo' => Point::makeGeodetic(1, 1),
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]))
            ->set('tableFilters.scan.geo.lat', 1)
            ->set('tableFilters.scan.geo.lon', 1)
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords($otherHits);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_date_filters_for_created_at(): void
    {
        [$otherHits, $hit] = $this->fakeHits(scanAttributes: [
            'scanned_at' => now()->subDays(30),
        ]);

        // Force the hit to be older than 30 days.
        $hit->created_at = now()->subDays(30);
        $hit->saveQuietly();

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits)
            ->assertCanNotSeeTableRecords([$hit])
            ->set('tableFilters.hits.created_at.start', now()->subDays(30)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]));
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_last_7d_search_includes_yesterday_when_hit_date_bounds_are_still_today(): void
    {
        Table::configureUsing(fn (Table $table) => $table->deferFilters());

        try {
            [$todayHits, $yesterdayHit] = $this->fakeHits();

            $zone = 'America/Chicago';
            $todayHits->each(function (Hit $hit) use ($zone): void {
                $hit->created_at = now($zone);
                $hit->saveQuietly();
            });
            $yesterdayHit->created_at = now($zone)->subDay()->setTime(12, 0);
            $yesterdayHit->saveQuietly();

            $todayStart = now($zone)->startOfDay()->format('Y-m-d H:i:s');
            $todayEnd = now($zone)->format('Y-m-d H:i:s');

            Livewire::test(ListHits::class)
                ->assertSuccessful()
                ->set('tableFilters.filters.hit_type', 'all')
                ->set('tableDeferredFilters.filters.hit_type', 'all')
                ->assertCanSeeTableRecords($todayHits)
                ->assertCanNotSeeTableRecords([$yesterdayHit])
                ->set('tableDeferredFilters.hits.created_at.date_toggles', 'last_7d')
                ->set('tableDeferredFilters.hits.created_at.start', $todayStart)
                ->set('tableDeferredFilters.hits.created_at.end', $todayEnd)
                ->call('applyTableFilters')
                ->assertCanSeeTableRecords($todayHits->merge([$yesterdayHit]));
        } finally {
            Table::configureUsing(fn (Table $table) => $table->deferFilters(false));
        }
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_date_filters_for_scanned_at(): void
    {
        [$otherHits, $hit] = $this->fakeHits(scanAttributes: [
            'scanned_at' => now()->subDays(28),
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits->merge([$hit]))
            ->set('tableFilters.scans.scanned_at.start', now()->subDays(28)->startOfDay())
            ->set('tableFilters.scans.scanned_at.end', now()->subDays(28)->endOfDay())
            ->assertCanNotSeeTableRecords($otherHits)
            ->assertCanSeeTableRecords([$hit]);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_disposition_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        // Invalid Scan on first hit - Update existing metadata
        $hit->scan->updateOrCreateMetadata()->update([
            'meta' => [
                'disposition' => DispositionEnum::INVALID_SCAN->value,
            ],
        ]);

        $hit->refresh();

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->set('tableFilters.filters.dispositions.0', DispositionEnum::NEW_HIT->value)
            ->assertCanSeeTableRecords($otherHits)
            ->assertCanNotSeeTableRecords([$hit])
            ->set('tableFilters.filters.dispositions.0', DispositionEnum::INVALID_SCAN->value)
            ->assertCanNotSeeTableRecords($otherHits)
            ->assertCanSeeTableRecords([$hit]);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_clients_filter(): void
    {
        $client = Org::factory()->create();

        [$otherHits, $hit] = $this->fakeHits(hotlistAttributes: [
            'client_org_id' => $client->id,
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits)
            ->assertCanSeeTableRecords([$hit])
            ->set('tableFilters.filters.clients.0', $client->id)
            ->assertCanNotSeeTableRecords($otherHits)
            ->assertCanSeeTableRecords([$hit]);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_lienholder_filter(): void
    {
        $lienholder = Org::factory()->create();

        [$otherHits, $hit] = $this->fakeHits(hotlistAttributes: [
            'lienholder_org_id' => $lienholder->id,
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherHits)
            ->assertCanSeeTableRecords([$hit])
            ->set('tableFilters.filters.lienholders.0', $lienholder->id)
            ->assertCanNotSeeTableRecords($otherHits)
            ->assertCanSeeTableRecords([$hit]);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_cannot_see_overwatch_hits(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        $this->assertNotEquals(HitType::OverWatch->value, $hit->hit_type);

        $hit->update([
            'hit_type' => HitType::OverWatch->value,
        ]);

        $hit->refresh();

        $this->assertEquals(HitType::OverWatch->value, $hit->hit_type);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->set('tableFilters.filters.hit_type', 'all')
            ->assertCanNotSeeTableRecords([$hit])
            // Explicitly set invalid type
            ->set('tableFilters.filters.hit_type', $hit->hit_type)
            ->assertCanNotSeeTableRecords([$hit])
            // Explicitly search for it.
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.filters.scan.ocr', $hit->scan->ocr)
            ->assertCanNotSeeTableRecords([$hit]);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_kit_user_filter(): void
    {
        $kit1 = Kit::factory()->forOrg($this->user->org)->create();
        $kit2 = Kit::factory()->forOrg($this->user->org)->create();

        $hotlist1 = Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) use ($kit1) {
                Scan::factory()
                    ->for($kit1)
                    ->create([
                        'ocr' => $hotlist->plate,
                    ]);
            })
            ->create([
                'org_id' => $this->user->org_id,
            ]);

        $hotlist2 = Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) use ($kit2) {
                Scan::factory()
                    ->for($kit2)
                    ->create([
                        'ocr' => $hotlist->plate,
                    ]);
            })
            ->create([
                'org_id' => $this->user->org_id,
            ]);

        $hits = Hit::all();
        $this->assertCount(2, $hits);

        $hit1 = $hits->firstWhere('kit_id', $kit1->id);
        $hit2 = $hits->firstWhere('kit_id', $kit2->id);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords([$hit1, $hit2])
            ->set('tableFilters.filters.kits.0', $kit1->id)
            ->assertCanSeeTableRecords([$hit1])
            ->assertCanNotSeeTableRecords([$hit2])
            ->set('tableFilters.filters.kits.0', $kit2->id)
            ->assertCanNotSeeTableRecords([$hit1])
            ->assertCanSeeTableRecords([$hit2]);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_cannot_see_nicb_hits(): void
    {
        [$otherHits, $hit] = $this->fakeHits(hotlistAttributes: [
            'integration_id' => IntegrationEnum::NICB->value,
        ]);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->set('tableFilters.filters.hit_type', 'all')
            ->assertCanNotSeeTableRecords([$hit])
            // Explicitly search for it.
            ->set('tableFilters.filters.scan.ocr', $hit->scan->ocr)
            ->assertCanNotSeeTableRecords([$hit]);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_logs_scan_plate_filter_activity(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.scan.ocr', $hit->scan->ocr)
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => HitsSearch::$log_name,
            'description' => $this->user->email.' searched for hits by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', HitsSearch::$log_name)
            ->where('description', $this->user->email.' searched for hits by Plate')
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals([$hit->scan->ocr], $activity->properties['plateNumber']);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_logs_hotlist_vin_filter_activity(): void
    {
        [$otherHits, $hit] = $this->fakeHits(hotlistAttributes: [
            'vin' => '123456',
        ]);

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hotlist.vin', '123456')
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => HitsSearch::$log_name,
            'description' => $this->user->email.' searched for hits by VIN',
        ]);

        $activity = Activity::query()
            ->where('log_name', HitsSearch::$log_name)
            ->where('description', $this->user->email.' searched for hits by VIN')
            ->first();

        $this->assertArrayHasKey('vin', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['vin']);
    }

    #[Group('resources')]
    #[Group('hits')]
    public function test_disposition_column_renders_the_postgres_meta_disposition(): void
    {
        $column = Livewire::test(ListHits::class)->instance()->getTable()->getColumn('scan_metadata');

        $column->record(new Hit(['id' => 1, 'scan_metadata' => null]))->clearCachedState();
        $this->assertStringContainsString('New Hit', \strip_tags($column->toEmbeddedHtml()));

        $column->record(new Hit(['id' => 1, 'scan_metadata' => '{"disposition":"Invalid"}']))->clearCachedState();
        $this->assertStringContainsString('Invalid', \strip_tags($column->toEmbeddedHtml()));
    }

    /**
     * @return array<string, array{key: string, label: string, color: string, unique: int|null, total: int|null}>
     */
    private function metricCards(Testable $component): array
    {
        $cards = $component->instance()->getWidgetData()['hitMetricCards'];

        return \collect($cards)->keyBy('key')->all();
    }

    private function hitsPageWithOpenFilters(): Testable
    {
        return Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay());
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_are_the_four_expected_cards_in_order(): void
    {
        $this->fakeHits();

        $cards = Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->instance()
            ->getWidgetData()['hitMetricCards'];

        $this->assertSame(
            ['live-hits', 'historical-hits', 'staging-live-hits', 'staging-historical-hits'],
            \array_column($cards, 'key'),
        );

        $this->assertSame(
            ['Live Hits', 'Direct Historical Hits', 'Staging Live Hits', 'Staging Historical Hits'],
            \array_column($cards, 'label'),
        );
    }

    /**
     * The seam every other card test skips: the cases below read getWidgetData() off the
     * page, so they all still pass if the widget is unregistered or its prop renamed —
     * i.e. with the feature absent from the page entirely. This one renders it.
     *
     * It must assert on the FIRST render, with no ->set() before it. Livewire renders a
     * nested child in full only once; on any later parent render it emits a bare
     * `<div wire:id … wire:name>` stub and lets the client morph the child from its own
     * payload. So after a ->set() the page HTML still contains "HitMetricCards" but none
     * of the widget's markup, and asserting there fails against a feature that works.
     */
    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_widget_is_rendered_on_the_page(): void
    {
        $this->fakeHits();

        Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->assertSeeHtml('data-hit-metric-card="live-hits"')
            ->assertSeeHtml('data-hit-metric-card="historical-hits"')
            ->assertSeeHtml('data-hit-metric-card="staging-live-hits"')
            ->assertSeeHtml('data-hit-metric-card="staging-historical-hits"')
            ->assertSeeText('Live Hits')
            ->assertSeeText('Direct Historical Hits')
            ->assertSeeText('total hits');
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_count_each_hit_type_in_the_filtered_set(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        $cards = $this->metricCards($this->hitsPageWithOpenFilters());

        $this->assertSame(1, $cards['live-hits']['total']);
        $this->assertSame(1, $cards['historical-hits']['total']);
        $this->assertSame(0, $cards['staging-live-hits']['total']);
        $this->assertSame(0, $cards['staging-historical-hits']['total']);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_card_headline_dedupes_by_plate_and_description_does_not(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        // A second Live hit on the SAME plate: one unique plate, two raw hits.
        $liveHit = $otherHits->first();
        Scan::factory()
            ->for($liveHit->scan->kit)
            ->create(['ocr' => $liveHit->hotlist->plate]);

        $cards = $this->metricCards($this->hitsPageWithOpenFilters());

        $this->assertSame(2, $cards['live-hits']['total']);
        $this->assertSame(1, $cards['live-hits']['unique']);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_react_to_a_filter_change(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        $component = $this->hitsPageWithOpenFilters();

        $before = $this->metricCards($component);
        $this->assertSame(1, $before['live-hits']['total']);
        $this->assertSame(1, $before['historical-hits']['total']);

        $component->set('tableFilters.filters.hit_type', $hit->hit_type);

        $after = $this->metricCards($component);
        $this->assertSame(0, $after['live-hits']['total']);
        $this->assertSame(1, $after['historical-hits']['total']);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_react_to_the_clickable_filter_bypass(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        $component = $this->hitsPageWithOpenFilters();

        $this->assertSame(1, $this->metricCards($component)['live-hits']['total']);

        // HasClickableFilter::addFilter() writes tableFilters directly, bypassing
        // Filament's handleTableFilterUpdates() choke point (AC-3).
        $component->call('addFilter', 'scan.ocr', $hit->scan->ocr);

        $cards = $this->metricCards($component);
        $this->assertSame(0, $cards['live-hits']['total']);
        $this->assertSame(1, $cards['historical-hits']['total']);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_follow_the_hit_date_filter(): void
    {
        [$otherHits, $hit] = $this->fakeHits();

        Hit::query()->update(['created_at' => now()->subDays(3)]);

        $insideWindow = $this->metricCards($this->hitsPageWithOpenFilters());
        $this->assertSame(1, $insideWindow['live-hits']['total']);

        $outsideWindow = $this->metricCards(
            Livewire::test(ListHits::class)
                ->assertSuccessful()
                ->set('tableFilters.filters.hit_type', 'all')
                ->set('tableFilters.hits.created_at.start', now()->subDay()->startOfDay())
                ->set('tableFilters.hits.created_at.end', now()->endOfDay())
        );

        $this->assertSame(0, $outsideWindow['live-hits']['total']);
        $this->assertSame(0, $outsideWindow['historical-hits']['total']);
    }

    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_widget_renders_the_page_supplied_counts(): void
    {
        Livewire::test(HitMetricCards::class, [
            'hitMetricCards' => [
                ['key' => 'live-hits', 'label' => 'Live Hits', 'color' => 'danger', 'unique' => 3, 'total' => 7],
                ['key' => 'historical-hits', 'label' => 'Direct Historical Hits', 'color' => 'danger', 'unique' => null, 'total' => null],
            ],
        ])
            ->assertSuccessful()
            ->assertSeeHtml('data-hit-metric-card="live-hits"')
            ->assertSeeText('Live Hits')
            ->assertSeeText('7 total hits')
            ->assertSeeHtml('data-hit-metric-card="historical-hits"')
            ->assertSeeText('Direct Historical Hits')
            ->assertSeeText('Count unavailable')
            ->assertSeeText('—');
    }

    /**
     * Pins `#[Reactive]` on HitMetricCards::$hitMetricCards directly, rather than through a
     * full ListHits render: Livewire only fully re-renders a nested child on its FIRST
     * mount, so nothing observable in the parent's HTML changes on a later render whether or
     * not the prop is reactive (see test_hit_metric_cards_widget_is_rendered_on_the_page).
     * The actual mechanism that makes `#[Reactive]` work is BaseReactive::hydrate() reading a
     * value stashed by the parent's re-render into
     * SupportReactiveProps::$pendingChildParams — this reproduces exactly that stash against
     * an already-mounted child and then re-hydrates it, which is the same code path a real
     * browser's follow-up request for the child exercises. Deleting `#[Reactive]` makes this
     * fail immediately, because nothing on the property would implement hydrate() to read the
     * stash at all.
     */
    #[Group('resources')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_hit_metric_cards_prop_is_wired_reactive(): void
    {
        $component = Livewire::test(HitMetricCards::class, [
            'hitMetricCards' => [
                ['key' => 'live-hits', 'label' => 'Live Hits', 'color' => 'danger', 'unique' => 1, 'total' => 1],
            ],
        ]);

        $id = $component->instance()->getId();

        $updated = [
            ['key' => 'live-hits', 'label' => 'Live Hits', 'color' => 'danger', 'unique' => 99, 'total' => 99],
        ];

        SupportReactiveProps::$pendingChildParams[$id] = ['hitMetricCards' => $updated];

        $component->call('$refresh');

        $this->assertSame($updated, $component->get('hitMetricCards'));
    }
}
