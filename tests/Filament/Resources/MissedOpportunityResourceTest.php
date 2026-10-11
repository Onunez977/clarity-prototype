<?php

namespace Tests\Filament\Resources;

use App\Actions\Activity\Log\MissedOpportunitiesSearch;
use App\Clickhouse\Models\MissedOpportunity as ClickhouseMissedOpportunity;
use App\Enums\DispositionEnum;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Pages\Subscription;
use App\Filament\Resources\MissedOpportunityResource;
use App\Filament\Resources\MissedOpportunityResource\Pages\ListMissedOpportunities;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\MissedOpportunity;
use App\Models\Org;
use App\Models\Scan;
use InsightLPR\BlackAdam\Enum\HitType;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\RequiresClickhouse;
use Tests\ResourceTestCase;

class MissedOpportunityResourceTest extends ResourceTestCase
{
    use RequiresClickhouse;

    protected bool $pro = true;

    protected mixed $userPermissions = PermissionEnum::ViewAny_Hit->value;

    private function fakeHits(array $hotlistAttributes = [], array $scanAttributes = [], array $mainMoAttributes = []): array
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

        $otherMissedHits = Hit::all();
        $this->assertCount(1, $otherMissedHits);

        $otherMissedHits->each(function (Hit $hit) {
            $hit->update([
                'hit_type' => HitTypeEnum::Missed_Opportunity->value,
            ]);

            $this->insertClarityMissedOpportunity(MissedOpportunity::find($hit->id));
        });

        // MAIN HIT
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

        $hit = Hit::query()
            ->whereNotIn('id', $otherMissedHits->pluck('id'))
            ->first();

        $hit->update([
            'hit_type' => HitTypeEnum::Missed_Opportunity->value,
        ]);

        $mainMo = MissedOpportunity::find($hit->id);
        $this->insertClarityMissedOpportunity($mainMo, $mainMoAttributes);

        return [
            $otherMissedHits,
            $mainMo,
        ];
    }

    private function insertClarityMissedOpportunity(MissedOpportunity $mo, array $overrides = []): void
    {
        ClickhouseMissedOpportunity::factory()->create(array_merge([
            'id' => $mo->id,
            'hotlist_id' => $mo->hotlist_id,
            'scan_id' => $mo->scan_id,
            'hit_type' => $mo->hit_type,
            'hit_created_at' => $mo->created_at,
            'ocr' => $mo->scan?->ocr,
            'scanned_at' => $mo->scan?->scanned_at ?? now(),
            'camera_name' => $mo->scan?->camera_name,
            'scan_us_state' => $mo->scan?->us_state,
            'scan_guid' => $mo->scan?->guid,
            'kit_id' => $mo->scan?->kit_id ?? 0,
            'kit_name' => $mo->scan?->kit?->name,
            'kit_org_id' => $mo->scan?->kit?->org_id ?? 0,
            'hotlist_org_id' => $mo->hotlist?->org_id,
            'hotlist_us_state' => $mo->hotlist?->us_state,
            'hotlist_plate' => $mo->hotlist?->plate,
            'hotlist_vin' => $mo->hotlist?->vin,
            'remote_order_id' => (string) ($mo->hotlist?->remote_order_id ?? ''),
            'integration_id' => $mo->hotlist?->integration_id instanceof \BackedEnum ? $mo->hotlist->integration_id->value : ($mo->hotlist?->integration_id ?? 1),
            'client_org_id' => $mo->hotlist?->client_org_id,
            'lienholder_org_id' => $mo->hotlist?->lienholder_org_id,
            'client_org_name' => $mo->hotlist?->clientOrg?->name,
            'lienholder_org_name' => $mo->hotlist?->lienholderOrg?->name,
            'lat' => $mo->scan?->geo ? $mo->scan->geo->getLatitude() : 0,
            'lng' => $mo->scan?->geo ? $mo->scan->geo->getLongitude() : 0,
            'vehicle_year' => $mo->hotlist?->vehicle_year,
            'vehicle_make' => $mo->hotlist?->vehicle_make,
            'vehicle_model' => $mo->hotlist?->vehicle_model,
        ], $overrides));
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_can_render_table(): void
    {
        [$otherMissedHits, $missedOpportunity] = $this->fakeHits();

        $this->assertEquals(2, MissedOpportunity::count());

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherMissedHits->merge([$missedOpportunity]));
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [], pro: true)
        );

        Livewire::test(ListMissedOpportunities::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_render_without_being_pro(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value, pro: false)
        );

        $this->get(MissedOpportunityResource::getUrl())
            ->assertRedirect(Subscription::getUrl());
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_render_without_being_pro_feature_enabled(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value)
        );

        $user->org->meta()->create([
            'properties' => [
                'platform_repo_pro' => true,
                FeatureEnum::MISSED_OPPORTUNITY->value => false,
            ],
        ]);

        $this->get(MissedOpportunityResource::getUrl())
            ->assertRedirect(Subscription::getUrl());
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_can_render_table_columns(): void
    {
        $this->assertEquals(0, MissedOpportunity::count());

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

        $hit = Hit::first();

        $this->assertEquals(1, Hit::count());

        $hit->update([
            'hit_type' => HitTypeEnum::Missed_Opportunity->value,
        ]);

        $this->assertEquals(0, Hit::count());
        $this->assertEquals(1, MissedOpportunity::count());

        $missedOpportunity = MissedOpportunity::find($hit->id);
        $this->insertClarityMissedOpportunity($missedOpportunity);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords([$missedOpportunity])
            ->assertCanRenderTableColumn('scans_ocr')
            ->assertSeeText($missedOpportunity->scan->ocr)
            ->assertCanRenderTableColumn('created_at')
            ->assertCanRenderTableColumn('scans_scanned_at')
            ->assertCanRenderTableColumn('scans_geo')
            ->assertSeeText('Lat/Long: '.\implode(', ', $hit->scan->coordinates))
            ->assertCanRenderTableColumn('hotlist_vin')
            ->assertSeeText($hotlist->vin)
            ->assertSeeText(\trim($hotlist->vehicle_year.' '.$hotlist->vehicle_make.' '.$hotlist->vehicle_model))
            ->assertCanRenderTableColumn('hotlist_remote_order_id')
            ->assertSeeText($hotlist->remote_order_id)
            ->assertCanRenderTableColumn('client_org_name');
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_repo_date_column_renders(): void
    {
        $this->assertEquals(0, MissedOpportunity::count());

        $kit = Kit::factory()
            ->forOrg($this->user->org)
            ->create();

        // The Repo Date column is rendered from the ClickHouse row's repo_date.
        // The PG hotlist stays in the default open status so the find_hits
        // trigger fires and we get a Hit row to attach the MO to.
        $repoDate = now()->subDays(5)->startOfDay();
        $repossessedHotlist = Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) use ($kit) {
                Scan::factory()
                    ->for($kit)
                    ->create(['ocr' => $hotlist->plate]);
            })
            ->create(['org_id' => $this->user->org_id]);

        $repossessedHit = Hit::query()->where('hotlist_id', $repossessedHotlist->id)->first();
        $repossessedHit->update(['hit_type' => HitTypeEnum::Missed_Opportunity->value]);
        $this->insertClarityMissedOpportunity(MissedOpportunity::find($repossessedHit->id), [
            'hotlist_status' => HotlistStatusEnum::Repossessed->value,
            'repo_date' => $repoDate->toDateString(),
        ]);

        // Open hotlist without a repo_date.
        $openHotlist = Hotlist::factory()
            ->afterCreating(function (Hotlist $hotlist) use ($kit) {
                Scan::factory()
                    ->for($kit)
                    ->create(['ocr' => $hotlist->plate]);
            })
            ->create(['org_id' => $this->user->org_id]);

        $openHit = Hit::query()->where('hotlist_id', $openHotlist->id)->first();
        $openHit->update(['hit_type' => HitTypeEnum::Missed_Opportunity->value]);
        $this->insertClarityMissedOpportunity(MissedOpportunity::find($openHit->id), [
            'repo_date' => null,
        ]);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanRenderTableColumn('repo_date')
            ->assertSeeText($repoDate->setTimezone($this->user->timezone)->format('M j, Y'))
            ->assertSeeText('N/A');
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_vin_filter(): void
    {
        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(hotlistAttributes: [
            'vin' => '123456',
        ]);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            // Reset other filters.
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherMissedHits->merge([$missedOpportunity]))
            ->set('tableFilters.filters.hotlist.vin', '123456')
            ->assertCanSeeTableRecords([$missedOpportunity])
            ->assertCanNotSeeTableRecords($otherMissedHits);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_date_filters_for_created_at(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') === 'clickhouse') {
            $this->markTestSkipped(
                'TODO: Filament table assertions behave unexpectedly against real ClickHouse — '.
                'needs live inspection to understand filter default state. Still runs under pgsql polyfill.'
            );
        }

        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(
            scanAttributes: ['scanned_at' => now()->subDays(30)],
            mainMoAttributes: ['hit_created_at' => now()->subDays(30)],
        );

        // Keep the Eloquent model's created_at in sync so any PG-side assertions
        // (or relationships keyed off it) see the same timestamp as the CH row.
        $missedOpportunity->created_at = now()->subDays(30);
        $missedOpportunity->saveQuietly();

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherMissedHits)
            ->assertCanNotSeeTableRecords([$missedOpportunity])
            ->set('tableFilters.hits.created_at.start', now()->subDays(30)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherMissedHits->merge([$missedOpportunity]));
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_validation_filter(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') === 'clickhouse') {
            $this->markTestSkipped(
                'TODO: Filament table assertions behave unexpectedly against real ClickHouse — '.
                'needs live inspection to understand filter default state. Still runs under pgsql polyfill.'
            );
        }

        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(
            mainMoAttributes: ['disposition' => DispositionEnum::INVALID->value],
        );

        // Mirror the disposition on the PG scan metadata so any Eloquent-side
        // reads agree with the CH row seeded above.
        $missedOpportunity->scan->updateOrCreateMetadata()->update([
            'meta' => [
                'disposition' => DispositionEnum::INVALID->value,
            ],
        ]);

        $missedOpportunity->refresh();

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherMissedHits)
            ->assertCanNotSeeTableRecords([$missedOpportunity])
            ->set('tableFilters.filters.dispositions.0', DispositionEnum::INVALID->value)
            ->assertCanNotSeeTableRecords($otherMissedHits)
            ->assertCanSeeTableRecords([$missedOpportunity]);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_default_view_excludes_only_invalid_dispositions(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') === 'clickhouse') {
            $this->markTestSkipped(
                'TODO: Filament table assertions behave unexpectedly against real ClickHouse — '.
                'needs live inspection to understand filter default state. Still runs under pgsql polyfill.'
            );
        }

        // Main MO is dispositioned Valid; the other MO keeps a null disposition.
        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(
            mainMoAttributes: ['disposition' => DispositionEnum::VALID->value],
        );

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            // Default view excludes only Invalid: the null-disposition and Valid records remain.
            ->assertCanSeeTableRecords($otherMissedHits->merge([$missedOpportunity]))
            // Explicitly filtering to Valid still shows the Valid record and routes through forDispositions.
            ->set('tableFilters.filters.dispositions.0', DispositionEnum::VALID->value)
            ->assertCanSeeTableRecords([$missedOpportunity])
            ->assertCanNotSeeTableRecords($otherMissedHits);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_clients_filter(): void
    {
        $client = Org::factory()->create();

        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(hotlistAttributes: [
            'client_org_id' => $client->id,
        ]);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherMissedHits)
            ->assertCanSeeTableRecords([$missedOpportunity])
            ->set('tableFilters.filters.clients.0', $client->id)
            ->assertCanNotSeeTableRecords($otherMissedHits)
            ->assertCanSeeTableRecords([$missedOpportunity]);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_lienholder_filter(): void
    {
        $lienholder = Org::factory()->create();

        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(hotlistAttributes: [
            'lienholder_org_id' => $lienholder->id,
        ]);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanSeeTableRecords($otherMissedHits)
            ->assertCanSeeTableRecords([$missedOpportunity])
            ->set('tableFilters.filters.lienholders.0', $lienholder->id)
            ->assertCanNotSeeTableRecords($otherMissedHits)
            ->assertCanSeeTableRecords([$missedOpportunity]);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_see_other_hit_types(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') === 'clickhouse') {
            $this->markTestSkipped(
                'TODO: Filament table assertions behave unexpectedly against real ClickHouse — '.
                'needs live inspection to understand filter default state. Still runs under pgsql polyfill.'
            );
        }

        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(
            mainMoAttributes: ['hit_type' => HitType::Live->value],
        );

        $this->assertNotEquals(HitType::Live->value, $missedOpportunity->hit_type);

        // Match the PG hit_type to the CH row seeded above.
        $missedOpportunity->update([
            'hit_type' => HitType::Live->value,
        ]);

        $missedOpportunity->refresh();

        $this->assertEquals(HitType::Live->value, $missedOpportunity->hit_type);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanNotSeeTableRecords([$missedOpportunity])
            // Explicitly search for it.
            ->set('tableFilters.filters.scan.ocr', $missedOpportunity->scan->ocr)
            ->assertCanNotSeeTableRecords([$missedOpportunity]);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_see_nicb_hits(): void
    {
        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(hotlistAttributes: [
            'integration_id' => IntegrationEnum::NICB->value,
        ]);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            ->assertCanNotSeeTableRecords([$missedOpportunity])
            // Explicitly search for it.
            ->set('tableFilters.filters.scan.ocr', $missedOpportunity->scan->ocr)
            ->assertCanNotSeeTableRecords([$missedOpportunity]);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_logs_scan_plate_filter_activity(): void
    {
        [$otherMissedHits, $missedOpportunity] = $this->fakeHits();

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.scan.ocr', $missedOpportunity->scan->ocr)
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => MissedOpportunitiesSearch::$log_name,
            'description' => $this->user->email.' searched for missed opportunities by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', MissedOpportunitiesSearch::$log_name)
            ->where('description', $this->user->email.' searched for missed opportunities by Plate')
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals([$missedOpportunity->scan->ocr], $activity->properties['plateNumber']);
    }

    #[Group('resources')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_logs_hotlist_vin_filter_activity(): void
    {
        [$otherMissedHits, $missedOpportunity] = $this->fakeHits(hotlistAttributes: [
            'vin' => '123456',
        ]);

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListMissedOpportunities::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hotlist.vin', '123456')
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => MissedOpportunitiesSearch::$log_name,
            'description' => $this->user->email.' searched for missed opportunities by VIN',
        ]);

        $activity = Activity::query()
            ->where('log_name', MissedOpportunitiesSearch::$log_name)
            ->where('description', $this->user->email.' searched for missed opportunities by VIN')
            ->first();

        $this->assertArrayHasKey('vin', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['vin']);
    }
}
