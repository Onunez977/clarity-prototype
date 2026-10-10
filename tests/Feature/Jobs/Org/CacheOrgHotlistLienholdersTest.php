<?php

namespace Tests\Feature\Jobs\Org;

use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Jobs\Org\CacheOrgHotlistLienholders;
use App\Models\Org;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class CacheOrgHotlistLienholdersTest extends TestCase
{
    use RequiresClickhouse;

    #[Group('jobs')]
    public function test_it_degrades_gracefully_without_caching_on_clickhouse_failure(): void
    {
        Config::set('app.debug', false);

        $org = Org::factory()->create();

        $this->forceClickhouseConnectionToFail();

        (new CacheOrgHotlistLienholders(org: $org))->handle();

        $this->assertNull(cache()->get('hotlist-lienholders:'.$org->id));
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_can_run(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        $this->assertNull(cache()->get('hotlist-lienholders:'.$org->id));

        (new CacheOrgHotlistLienholders(org: $org))->handle();

        $this->assertNotNull(cache()->get('hotlist-lienholders:'.$org->id));
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_caches_correct_lienholder_data(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        ClickhouseHotlist::factory()->forOrg($org)->create([
            'lienholder_org_id' => 151,
            'lienholder_org_name' => 'Test Lienholder LLC',
        ]);

        (new CacheOrgHotlistLienholders(org: $org))->handle();

        $cached = cache()->get('hotlist-lienholders:'.$org->id);

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertCount(1, $cached);
        $this->assertEquals('Test Lienholder LLC', $cached->get(151));
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_caches_empty_collection_when_no_lienholders(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        (new CacheOrgHotlistLienholders(org: $org))->handle();

        $cached = cache()->get('hotlist-lienholders:'.$org->id);

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertEmpty($cached);
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_deduplicates_lienholders(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        ClickhouseHotlist::factory()->count(3)->forOrg($org)->create([
            'lienholder_org_id' => 161,
            'lienholder_org_name' => 'Alpha Lienholder',
        ]);
        ClickhouseHotlist::factory()->count(2)->forOrg($org)->create([
            'lienholder_org_id' => 171,
            'lienholder_org_name' => 'Beta Lienholder',
        ]);

        (new CacheOrgHotlistLienholders(org: $org))->handle();

        $cached = cache()->get('hotlist-lienholders:'.$org->id);

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertCount(2, $cached);
        $this->assertEquals('Alpha Lienholder', $cached->get(161));
        $this->assertEquals('Beta Lienholder', $cached->get(171));
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_skips_null_named_lienholders(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        ClickhouseHotlist::factory()->forOrg($org)->create([
            'lienholder_org_id' => 181,
            'lienholder_org_name' => 'Real Lienholder',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'lienholder_org_id' => 191,
            'lienholder_org_name' => null,
        ]);

        (new CacheOrgHotlistLienholders(org: $org))->handle();

        $cached = cache()->get('hotlist-lienholders:'.$org->id);

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertCount(1, $cached);
        $this->assertEquals('Real Lienholder', $cached->get(181));
    }

    /**
     * Point the read connection at a dead port so a real connect failure surfaces
     * as a ClickhouseException, without needing live ClickHouse.
     */
    private function forceClickhouseConnectionToFail(): void
    {
        Config::set('database.connections.clarity_clickhouse.driver', 'clickhouse');
        Config::set('database.connections.clarity_clickhouse.host', '127.0.0.1');
        Config::set('database.connections.clarity_clickhouse.port', '1');
        Config::set('database.connections.clarity_clickhouse.https', false);
        Config::set('database.connections.clarity_clickhouse.options', ['protocol' => 'http']);
        Config::set('database.connections.clarity_clickhouse.timeout_connect', 1);
        Config::set('database.connections.clarity_clickhouse.timeout_query', 1);
        Config::set('database.connections.clarity_clickhouse.retries', 0);

        DB::purge('clarity_clickhouse');
    }
}
