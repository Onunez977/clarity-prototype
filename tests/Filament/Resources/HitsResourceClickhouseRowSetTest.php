<?php

declare(strict_types=1);

namespace Tests\Filament\Resources;

use App\Enums\DispositionEnum;
use App\Enums\HitTypeEnum;
use App\Filament\Resources\HitsResource\Pages\ListHits;
use App\Models\Kit;
use App\Models\Org;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\HitType;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\ResourceTestCase;

/**
 * DEV-2614: the row set the ClickHouse Hits list returns for a normal user, routed by config
 * (no impersonation). Scoped by kit only, like Postgres: the hit's hotlist org never narrows the set.
 */
#[Group('clickhouse')]
#[Group('hits')]
class HitsResourceClickhouseRowSetTest extends ResourceTestCase
{
    use RequiresClickhouse;

    protected mixed $userPermissions = PermissionEnum::ViewAny_Hit->value;

    private int $hitSeq;

    private string $plate;

    private Kit $kit;

    private Kit $foreignKit;

    private Org $foreignOrg;

    private Org $stagingOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();

        Carbon::setTestNow(Carbon::now('UTC')->startOfHour());

        Config::set('app.experimental.clickhouse.hits', true);

        $this->hitSeq = \random_int(100_000_000, 900_000_000);
        $this->plate = 'RS'.\strtoupper(\base_convert((string) \hrtime(true), 10, 36));

        $this->kit = Kit::factory()->forOrg($this->user->org)->create();
        $this->foreignOrg = Org::factory()->create();
        $this->foreignKit = Kit::factory()->forOrg($this->foreignOrg)->create();
        $this->stagingOrg = Org::factory()->create();

        Kit::forgetCachedKits((int) $this->user->org_id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('resources')]
    public function test_hits_on_own_kits_are_listed_whatever_their_hotlist_org(): void
    {
        $own = $this->seedHit();
        $stagingLive = $this->seedHit([
            'hit_type' => HitTypeEnum::Staging_Live->value,
            'hotlist_org_id' => $this->stagingOrg->id,
        ]);
        $stagingHistorical = $this->seedHit([
            'hit_type' => HitTypeEnum::Staging_Historical->value,
            'hotlist_org_id' => $this->stagingOrg->id,
        ]);
        $otherOrgOrder = $this->seedHit(['hotlist_org_id' => $this->foreignOrg->id]);

        $this->assertEqualsCanonicalizing(
            [$own, $stagingLive, $stagingHistorical, $otherOrgOrder],
            $this->listedIds($this->hitsPage(), [$own, $stagingLive, $stagingHistorical, $otherOrgOrder]),
        );
    }

    #[Group('resources')]
    public function test_foreign_kit_overwatch_and_nicb_hits_are_absent(): void
    {
        $own = $this->seedHit();
        $foreignKit = $this->seedHit([
            'kit_id' => $this->foreignKit->id,
            'kit_name' => $this->foreignKit->name,
            'kit_org_id' => $this->foreignOrg->id,
            'hotlist_org_id' => $this->foreignOrg->id,
        ]);
        $overWatch = $this->seedHit(['hit_type' => HitType::OverWatch->value]);
        $nicb = $this->seedHit(['integration_id' => IntegrationEnum::NICB->value]);

        $this->assertSame(
            [$own],
            $this->listedIds($this->hitsPage(), [$own, $foreignKit, $overWatch, $nicb]),
        );
    }

    #[Group('resources')]
    public function test_literal_new_hit_disposition_is_listed_under_the_default_filter(): void
    {
        $null = $this->seedHit(['disposition' => null]);
        $newHit = $this->seedHit(['disposition' => DispositionEnum::NEW_HIT->value]);
        $accessible = $this->seedHit(['disposition' => DispositionEnum::ACCESSIBLE->value]);
        $invalid = $this->seedHit(['disposition' => DispositionEnum::INVALID->value]);

        $this->assertEqualsCanonicalizing(
            [$null, $newHit, $accessible],
            $this->listedIds($this->hitsPage(), [$null, $newHit, $accessible, $invalid]),
        );
    }

    #[Group('resources')]
    public function test_disposition_column_renders_the_row_disposition(): void
    {
        $invalid = $this->seedHit(['disposition' => DispositionEnum::INVALID->value]);
        $null = $this->seedHit(['disposition' => null]);
        $newHit = $this->seedHit(['disposition' => DispositionEnum::NEW_HIT->value]);

        $page = $this->hitsPage()
            ->set('tableFilters.filters.dispositions', [
                DispositionEnum::NEW_HIT->value,
                DispositionEnum::INVALID->value,
            ]);

        $column = $page->instance()->getTable()->getColumn('scan_metadata');

        foreach ([
            [$invalid, DispositionEnum::INVALID->value],
            [$null, 'New Hit'],
            [$newHit, 'New Hit'],
        ] as [$id, $expected]) {
            $column->record($page->instance()->getTableRecord((string) $id))->clearCachedState();

            $this->assertStringContainsString($expected, \strip_tags($column->toEmbeddedHtml()));
        }
    }

    #[Group('resources')]
    public function test_metric_cards_count_staging_rows(): void
    {
        $this->seedHit();
        $this->seedHit([
            'hit_type' => HitTypeEnum::Staging_Live->value,
            'hotlist_org_id' => $this->stagingOrg->id,
        ]);
        $this->seedHit([
            'hit_type' => HitTypeEnum::Staging_Live->value,
            'hotlist_org_id' => $this->foreignOrg->id,
        ]);
        $this->seedHit([
            'hit_type' => HitTypeEnum::Staging_Historical->value,
            'hotlist_org_id' => $this->stagingOrg->id,
        ]);
        $this->seedHit([
            'kit_id' => $this->foreignKit->id,
            'kit_org_id' => $this->foreignOrg->id,
            'hotlist_org_id' => $this->foreignOrg->id,
            'hit_type' => HitTypeEnum::Staging_Live->value,
        ]);

        $page = $this->hitsPage();

        $cards = \collect($page->instance()->getWidgetData()['hitMetricCards'])
            ->keyBy('key')
            ->all();

        $this->assertSame(1, $cards['live-hits']['total']);
        $this->assertSame(2, $cards['staging-live-hits']['total']);
        $this->assertSame(1, $cards['staging-historical-hits']['total']);
        $this->assertSame(0, $cards['historical-hits']['total']);
    }

    private function hitsPage(): Testable
    {
        return Livewire::test(ListHits::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.hit_type', 'all')
            ->set('tableFilters.hits.created_at.start', now()->subDays(7)->startOfDay())
            ->set('tableFilters.hits.created_at.end', now()->endOfDay())
            // The RequiresClickhouse truncate targets the literal `clarity.*` DB, so rows accumulate across runs; scope to this run's plate.
            ->set('tableFilters.filters.scan.ocr', $this->plate);
    }

    /**
     * Only the ids this test seeded, so leftover rows in a shared CH database cannot skew the assertion.
     *
     * @param  list<int>  $seeded
     * @return list<int>
     */
    private function listedIds(Testable $component, array $seeded): array
    {
        return \collect($component->instance()->getTableRecords()->items())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->intersect($seeded)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedHit(array $overrides = []): int
    {
        // hits_hot drops rows older than 90 days at INSERT, so anchor to now().
        $id = $this->hitSeq++;

        DB::connection('clarity_clickhouse')->table('hits_hot')->insert(\array_merge([
            'id' => $id,
            'hotlist_id' => 1,
            'scan_id' => $id,
            'hit_type' => HitTypeEnum::Live->value,
            'hit_created_at' => now()->subHours(2)->toDateTimeString(),
            'ocr' => $this->plate,
            'scanned_at' => now()->subHours(2)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => $this->kit->id,
            'kit_name' => $this->kit->name,
            'kit_org_id' => $this->user->org_id,
            'hotlist_org_id' => $this->user->org_id,
            'hotlist_us_state' => 'TX',
            'hotlist_plate' => $this->plate,
            'hotlist_status' => 1,
            // Nullable(Int32): NULL != 5 is NULL, which would silently drop every seeded row.
            'integration_id' => 1,
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_peerdb_hotlist_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ], $overrides));

        return $id;
    }
}
