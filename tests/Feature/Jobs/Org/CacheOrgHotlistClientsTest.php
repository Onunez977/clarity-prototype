<?php

namespace Tests\Feature\Jobs\Org;

use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Jobs\Org\CacheOrgHotlistClients;
use App\Models\Org;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class CacheOrgHotlistClientsTest extends TestCase
{
    use RequiresClickhouse;

    #[Group('jobs')]
    public function test_it_degrades_gracefully_without_caching_on_clickhouse_failure(): void
    {
        Config::set('app.debug', false);

        $org = Org::factory()->create();

        $this->forceClickhouseConnectionToFail();

        (new CacheOrgHotlistClients(org: $org))->handle();

        $this->assertNull(cache()->get('hotlist-clients:'.$org->id));
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_can_run(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        $this->assertNull(cache()->get('hotlist-clients:'.$org->id));

        (new CacheOrgHotlistClients(org: $org))->handle();

        $this->assertNotNull(cache()->get('hotlist-clients:'.$org->id));
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_caches_correct_client_data(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        ClickhouseHotlist::factory()->forOrg($org)->create([
            'client_org_id' => 121,
            'client_org_name' => 'Test Client Corp',
        ]);

        (new CacheOrgHotlistClients(org: $org))->handle();

        $cached = cache()->get('hotlist-clients:'.$org->id);

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertCount(1, $cached);
        $this->assertEquals('Test Client Corp', $cached->get(121));
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_caches_empty_collection_when_no_clients(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        (new CacheOrgHotlistClients(org: $org))->handle();

        $cached = cache()->get('hotlist-clients:'.$org->id);

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertEmpty($cached);
    }

    #[Group('jobs')]
    #[Group('clickhouse')]
    public function test_it_deduplicates_clients(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        ClickhouseHotlist::factory()->count(3)->forOrg($org)->create([
            'client_org_id' => 131,
            'client_org_name' => 'Alpha Client',
        ]);
        ClickhouseHotlist::factory()->count(2)->forOrg($org)->create([
            'client_org_id' => 141,
            'client_org_name' => 'Beta Client',
        ]);

        (new CacheOrgHotlistClients(org: $org))->handle();

        $cached = cache()->get('hotlist-clients:'.$org->id);

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertCount(2, $cached);
        $this->assertEquals('Alpha Client', $cached->get(131));
        $this->assertEquals('Beta Client', $cached->get(141));
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
