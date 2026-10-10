<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Models\Org;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class OrgHotlistCacheTest extends TestCase
{
    use RequiresClickhouse;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('hotlist-cache')]
    #[Group('clickhouse')]
    public function test_get_hotlist_clients_returns_correct_data_on_happy_path(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        // Two rows for the same named client prove dedup, a null-name row proves
        // the name filter drops it, a lienholder-only row must not leak into the
        // clients list, and a soft-deleted (_peerdb_is_deleted) row must be excluded.
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'client_org_id' => 101,
            'client_org_name' => 'Alpha Client',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'client_org_id' => 101,
            'client_org_name' => 'Alpha Client',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'client_org_id' => 202,
            'client_org_name' => 'Beta Client',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'client_org_id' => 303,
            'client_org_name' => null,
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'lienholder_org_id' => 404,
            'lienholder_org_name' => 'Some Lienholder',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->deleted()->create([
            'client_org_id' => 505,
            'client_org_name' => 'Deleted Client',
        ]);

        $clients = $org->getHotlistClients();

        $this->assertInstanceOf(Collection::class, $clients);
        $this->assertSame([
            101 => 'Alpha Client',
            202 => 'Beta Client',
        ], $clients->toArray());
    }

    #[Group('hotlist-cache')]
    #[Group('clickhouse')]
    public function test_get_hotlist_lienholders_returns_correct_data_on_happy_path(): void
    {
        $this->setupRequiresClickhouse();

        $org = Org::factory()->create();

        ClickhouseHotlist::factory()->forOrg($org)->create([
            'lienholder_org_id' => 606,
            'lienholder_org_name' => 'Alpha Lienholder',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'lienholder_org_id' => 606,
            'lienholder_org_name' => 'Alpha Lienholder',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'lienholder_org_id' => 707,
            'lienholder_org_name' => null,
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->create([
            'client_org_id' => 808,
            'client_org_name' => 'Some Client',
        ]);
        ClickhouseHotlist::factory()->forOrg($org)->deleted()->create([
            'lienholder_org_id' => 909,
            'lienholder_org_name' => 'Deleted Lienholder',
        ]);

        $lienholders = $org->getHotlistLienholders();

        $this->assertInstanceOf(Collection::class, $lienholders);
        $this->assertSame([
            606 => 'Alpha Lienholder',
        ], $lienholders->toArray());
    }

    #[Group('hotlist-cache')]
    public function test_get_hotlist_clients_returns_empty_collection_when_clickhouse_fails(): void
    {
        Config::set('app.debug', false);

        $org = Org::factory()->create();

        $this->forceClickhouseConnectionToFail();

        $clients = $org->getHotlistClients();

        $this->assertInstanceOf(Collection::class, $clients);
        $this->assertTrue($clients->isEmpty());
    }

    #[Group('hotlist-cache')]
    public function test_get_hotlist_lienholders_returns_empty_collection_when_clickhouse_fails(): void
    {
        Config::set('app.debug', false);

        $org = Org::factory()->create();

        $this->forceClickhouseConnectionToFail();

        $lienholders = $org->getHotlistLienholders();

        $this->assertInstanceOf(Collection::class, $lienholders);
        $this->assertTrue($lienholders->isEmpty());
    }

    #[Group('hotlist-cache')]
    public function test_get_hotlist_clients_rethrows_clickhouse_exceptions_in_debug_mode(): void
    {
        Config::set('app.debug', true);

        $org = Org::factory()->create();

        $this->forceClickhouseConnectionToFail();

        $this->expectException(ClickhouseException::class);

        $org->getHotlistClients();
    }

    #[Group('hotlist-cache')]
    public function test_get_hotlist_lienholders_rethrows_clickhouse_exceptions_in_debug_mode(): void
    {
        Config::set('app.debug', true);

        $org = Org::factory()->create();

        $this->forceClickhouseConnectionToFail();

        $this->expectException(ClickhouseException::class);

        $org->getHotlistLienholders();
    }

    #[Group('hotlist-cache')]
    #[Group('clickhouse')]
    public function test_get_hotlist_clients_serves_stale_value_on_clickhouse_failure_after_warm(): void
    {
        $this->setupRequiresClickhouse();

        Config::set('app.debug', false);

        Carbon::setTestNow(Carbon::parse('2026-07-23 10:00:00'));

        $org = Org::factory()->create();

        ClickhouseHotlist::factory()->forOrg($org)->create([
            'client_org_id' => 111,
            'client_org_name' => 'Warm Client Corp',
        ]);

        // Warm the cache while ClickHouse is healthy.
        $warmed = $org->getHotlistClients();
        $this->assertSame('Warm Client Corp', $warmed->get(111));

        // Cross into the next day: past the freshness window (endOfDay) but
        // still inside the stale grace window (addDay()->endOfDay()).
        Carbon::setTestNow(Carbon::parse('2026-07-24 10:00:00'));

        $this->forceClickhouseConnectionToFail();

        $clients = $org->getHotlistClients();

        $this->assertInstanceOf(Collection::class, $clients);
        $this->assertFalse($clients->isEmpty());
        $this->assertSame('Warm Client Corp', $clients->get(111));
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
