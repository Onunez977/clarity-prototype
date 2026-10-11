<?php

namespace Tests\Filament\Resources;

use App\Clickhouse\Models\Scan as ClickhouseScan;
use App\Filament\Resources\ScansResource\Pages\ListScans;
use App\Models\Kit;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DEV-2615 — query SHAPE invariants for the Scans ClickHouse list.
 *
 * Prunes on current Postgres kit org_ids (plus kit_id). Moved-kit historical
 * parity is intentionally out of scope until a customer reports it.
 */
class ClickhouseScanQueryShapeTest extends TestCase
{
    #[Group('clickhouse')]
    public function test_the_real_page_query_scopes_by_org_and_kit(): void
    {
        $user = $this->createUser();
        $kit = Kit::factory()->forOrg($user->org)->create();
        $this->actingAs($user);

        Kit::forgetCachedKits((int) $user->org_id);

        $page = new ListScans;
        $page->bootedInteractsWithTable();

        $build = new ReflectionMethod(ListScans::class, 'buildClickhouseQuery');
        $build->setAccessible(true);
        $sql = $build->invoke($page)->toSql();

        $bare = str_replace(['`', '"'], '', $sql);
        $where = substr($bare, (int) stripos($bare, ' where '));

        $this->assertStringContainsString('kit_id in', $where);
        $this->assertStringContainsString('org_id in', $where);
        $this->assertStringNotContainsString('0 = 1', $where);
        $this->assertStringNotContainsString('org_id in (0)', $where);
        $this->assertStringContainsString((string) $user->org_id, $where);
        $this->assertStringContainsString((string) $kit->id, $where);
    }

    #[Group('clickhouse')]
    public function test_kit_ids_reach_the_query_so_it_never_degrades_to_match_nothing(): void
    {
        $user = $this->createUser();
        $kit = Kit::factory()->forOrg($user->org)->create();

        $kitIds = Kit::getKits((int) $user->org_id, withShared: false)->pluck('id')->all();

        $this->assertContains($kit->id, $kitIds);

        $sql = str_replace('`', '', (new ClickhouseScan)->newQuery()->forKits($kitIds)->toSql());
        $this->assertStringNotContainsString('0 = 1', $sql);
        $this->assertStringNotContainsString('kit_id in (0)', $sql);
    }

    #[Group('clickhouse')]
    public function test_it_filters_on_kit_ids(): void
    {
        $sql = (new ClickhouseScan)->newQuery()->forKits([10, 11, 12])->toSql();

        $this->assertStringContainsString('kit_id', $sql);
        $this->assertStringContainsString('10', $sql);
    }

    #[Group('clickhouse')]
    public function test_for_orgs_filters_on_org_ids_and_rejects_an_empty_set(): void
    {
        $sql = (new ClickhouseScan)->newQuery()->forOrgs([1254, 665727])->toSql();

        $this->assertStringContainsString('org_id', $sql);
        $this->assertStringContainsString('1254', $sql);

        $empty = str_replace('`', '', (new ClickhouseScan)->newQuery()->forOrgs([])->toSql());
        $this->assertStringContainsString('0 = 1', $empty);
    }

    #[Group('clickhouse')]
    public function test_it_never_adds_final_to_scans_cold(): void
    {
        $sql = (new ClickhouseScan)->newQuery()->forOrgs([1])->forKits([10])->toSql();

        $this->assertStringNotContainsStringIgnoringCase('final', $sql);
        $this->assertEquals('scans_cold', (new ClickhouseScan)->getTable());
    }

    #[Group('clickhouse')]
    public function test_the_hot_table_keeps_the_same_shape(): void
    {
        $sql = ClickhouseScan::hot()->newQuery()->forOrgs([1])->forKits([10])->toSql();

        $this->assertStringContainsString('scans_hot', $sql);
        $this->assertStringContainsString('org_id', $sql);
        $this->assertStringNotContainsStringIgnoringCase('final', $sql);
    }

    #[Group('clickhouse')]
    public function test_it_excludes_cdc_deleted_rows(): void
    {
        $sql = (new ClickhouseScan)->newQuery()->forOrgs([1])->forKits([10])->toSql();

        $this->assertStringContainsString('_peerdb_is_deleted', $sql);
    }
}
