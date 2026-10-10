<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\Kit;
use App\Models\Org;
use App\Models\ScoutCheckIn;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * AC6: PHP memory is O(matches), not O(addresses). A large synthetic
 * open-address set outside the kit bbox must not inflate RSS the way the
 * old PHP-side address array did.
 */
class ScoutRecordCheckInsMemoryTest extends TestCase
{
    use RequiresClickhouse;

    private const MAPPING = 'KIT-TEST-2342-MEM';

    /**
     * Budget for the command's peak-RSS delta after a CH-side seed (no
     * PHP address array). The old architecture allocated O(addresses) in
     * PHP; 20k far rows is large enough that that materialization would
     * exceed this budget. Seed via `numbers()` so the fixture itself does
     * not raise the process high-water mark before we snapshot.
     */
    private const RSS_BUDGET_BYTES = 16 * 1024 * 1024;

    private const FAR_ADDRESS_COUNT = 20_000;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        foreach (['sc_positions', 'hotlist', 'scout_open_addresses'] as $table) {
            DB::connection('clarity_clickhouse')->statement("TRUNCATE TABLE IF EXISTS {$this->clarityTable($table)}");
        }
    }

    private function clarityTable(string $table): string
    {
        return config('database.connections.clarity_clickhouse.database').'.'.$table;
    }

    #[Test]
    public function peak_rss_stays_bounded_as_the_open_address_set_grows(): void
    {
        $this->travelTo(now()->setTime(12, 0));

        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create([
            'kit_mapping' => strtolower(self::MAPPING),
        ]);
        $driver = User::factory()->forOrg($org)->forKit($kit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);
        setPermissionsTeamId($driver->org_id);
        $driver->givePermissionTo('clarity-scout');

        $hotlistId = 234200;
        DB::connection('clarity_clickhouse')->table($this->clarityTable('hotlist'))->insert([
            'id' => $hotlistId,
            'org_id' => $org->id,
            'status' => 1,
            'order_type' => 1,
            'is_staged' => false,
            'plate' => 'MEMTEST',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ]);

        $inRadiusAddressId = 23421000;
        $this->insertBookAddress($inRadiusAddressId, $hotlistId, $org->id, -97.7, 30.3);

        DB::connection('clarity_clickhouse')->table($this->clarityTable('sc_positions'))->insert([
            'kit_mapping' => self::MAPPING,
            'geo' => '(-97.7, 30.3)',
            'timestamp_utc' => now()->subMinutes(5)->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ]);

        $this->insertFarAddresses(self::FAR_ADDRESS_COUNT, $hotlistId, $org->id, 23422000);

        gc_collect_cycles();
        $peakBefore = memory_get_peak_usage(true);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $peakAfter = memory_get_peak_usage(true);
        $delta = max(0, $peakAfter - $peakBefore);

        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $kit->id)->count());
        $this->assertLessThan(
            self::RSS_BUDGET_BYTES,
            $delta,
            sprintf('peak-RSS delta %d exceeded the %d budget', $delta, self::RSS_BUDGET_BYTES),
        );
    }

    private function insertBookAddress(int $addressId, int $hotlistId, int $orgId, float $lng, float $lat): void
    {
        DB::connection('clarity_clickhouse')->table($this->clarityTable('scout_open_addresses'))->insert([
            'org_id' => $orgId,
            'is_staged' => false,
            'hotlist_id' => $hotlistId,
            'address_id' => $addressId,
            'geo' => "({$lng}, {$lat})",
            'refreshed_at' => now()->toDateTimeString(),
        ]);
    }

    private function insertFarAddresses(int $count, int $hotlistId, int $orgId, int $addressIdStart): void
    {
        $book = $this->clarityTable('scout_open_addresses');
        $refreshedAt = now()->toDateTimeString();

        DB::connection('clarity_clickhouse')->statement("
            INSERT INTO {$book}
                (org_id, is_staged, hotlist_id, address_id, geo, refreshed_at)
            SELECT
                {$orgId},
                false,
                {$hotlistId},
                {$addressIdStart} + number,
                (-90.0, 40.0 + (number * 0.0001)),
                toDateTime('{$refreshedAt}')
            FROM numbers({$count})
        ");
    }
}
