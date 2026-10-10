<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Clickhouse\Queries\ScoutCheckInMatchQuery;
use App\Models\ApprovalList;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\ScoutCheckIn;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class ScoutRecordCheckInsCommandTest extends TestCase
{
    use RequiresClickhouse;

    private const MAPPING = 'KIT-TEST-2313';

    private Org $org;

    private Kit $kit;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        // Flush so W3 slot leases and Org::stagingOrgIds() cache don't leak
        // across tests.
        Cache::flush();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $this->travelTo(now()->utc()->setTime(12, 0));

        $this->truncateConfiguredClickhouseTables();

        $this->org = Org::factory()->create();
        $this->makeOrgPro($this->org);
        $this->kit = Kit::factory()->forOrg($this->org)->create([
            'kit_mapping' => strtolower(self::MAPPING),
        ]);

        $this->driver = User::factory()->forOrg($this->org)->forKit($this->kit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);

        setPermissionsTeamId($this->driver->org_id);
        $this->driver->givePermissionTo('clarity-scout');
    }

    private function clarityTable(string $table): string
    {
        return config('database.connections.clarity_clickhouse.database').'.'.$table;
    }

    private function truncateConfiguredClickhouseTables(): void
    {
        foreach (['sc_positions', 'hotlist', 'scout_open_addresses'] as $table) {
            DB::connection('clarity_clickhouse')->statement("TRUNCATE TABLE IF EXISTS {$this->clarityTable($table)}");
        }
    }

    private function insertHotlist(array $overrides = []): int
    {
        static $hotlistId = 1000;
        $defaults = [
            'id' => $hotlistId++,
            'org_id' => $this->org->id,
            'status' => 1,
            'order_type' => 1,
            'is_staged' => false,
            'plate' => 'ABC1234',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ];
        $row = array_merge($defaults, $overrides);

        DB::connection('clarity_clickhouse')->table($this->clarityTable('hotlist'))->insert($row);

        return (int) $row['id'];
    }

    /**
     * Seed a row on clarity.scout_open_addresses (DEV-2386). The MV already
     * applied Open + plate-length + EWKT parse; tests insert the book row
     * the matcher reads.
     */
    private function insertOpenAssignmentAddress(
        int $hotlistId,
        float $lng,
        float $lat,
        ?int $orgId = null,
        bool $isStaged = false,
        ?int $addressId = null,
        ?Carbon $refreshedAt = null,
    ): int {
        static $nextAddressId = 5000;
        $resolvedAddressId = $addressId ?? $nextAddressId++;

        DB::connection('clarity_clickhouse')->table($this->clarityTable('scout_open_addresses'))->insert([
            'org_id' => $orgId ?? $this->org->id,
            'is_staged' => $isStaged,
            'hotlist_id' => $hotlistId,
            'address_id' => $resolvedAddressId,
            'geo' => "({$lng}, {$lat})",
            'refreshed_at' => ($refreshedAt ?? now())->toDateTimeString(),
        ]);

        return $resolvedAddressId;
    }

    /**
     * Mirrors KitHistoricPositionsQueryTest's fixture shape for
     * clarity.sc_positions: geo is a raw `(lng, lat)` Point literal.
     */
    private function insertScPosition(string $kitMapping, float $lng, float $lat, Carbon $at): void
    {
        DB::connection('clarity_clickhouse')->table($this->clarityTable('sc_positions'))->insert([
            'kit_mapping' => $kitMapping,
            'geo' => "({$lng}, {$lat})",
            'timestamp_utc' => $at->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ]);
    }

    /**
     * Simulates a book row dropping out of a rebuild (an order closing, or
     * an address moving to another hotlist) -- `mutations_sync = 1` makes
     * the ClickHouse mutation apply synchronously so the very next SELECT
     * (this command's next run) sees it.
     */
    private function deleteOpenAssignmentAddress(int $addressId): void
    {
        DB::connection('clarity_clickhouse')->statement(
            "ALTER TABLE {$this->clarityTable('scout_open_addresses')} DELETE WHERE address_id = {$addressId} SETTINGS mutations_sync = 1"
        );
    }

    #[Test]
    public function it_records_a_direct_and_staging_check_in_for_positions_within_the_radius(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $directId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($directId, -97.7, 30.3);

        $stagingOrg = Org::factory()->create();
        ApprovalList::query()->create([
            'agent_org_id' => $this->org->id,
            'staging_org_id' => $stagingOrg->id,
        ]);
        $stagingId = $this->insertHotlist([
            'org_id' => $stagingOrg->id,
            'is_staged' => true,
        ]);
        $this->insertOpenAssignmentAddress($stagingId, -97.71, 30.31, orgId: $stagingOrg->id, isStaged: true);

        // Both fixes land within ~160m of their respective open address.
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);
        $this->insertScPosition(self::MAPPING, -97.71, 30.31, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(2, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());

        $direct = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->where('is_staged', false)->first();
        $this->assertNotNull($direct);
        $this->assertSame($this->org->id, $direct->org_id);
        $this->assertSame($directId, $direct->hotlist_id);
        $this->assertSame($fixAt->copy()->startOfHour()->toDateTimeString(), $direct->hour_bucket->toDateTimeString());
        $this->assertEqualsWithDelta(-97.7, $direct->lng, 0.001);
        $this->assertEqualsWithDelta(30.3, $direct->lat, 0.001);

        $staged = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->where('is_staged', true)->first();
        $this->assertNotNull($staged);
        $this->assertSame($stagingId, $staged->hotlist_id);
    }

    #[Test]
    public function running_it_twice_does_not_duplicate_rows(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();
        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Test]
    public function a_position_outside_the_radius_produces_no_check_in(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        // ~1.1km north of the fix below — well outside the 160m radius.
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.31);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(0, ScoutCheckIn::query()->count());
    }

    #[Test]
    public function a_position_within_the_bbox_but_beyond_the_radius_produces_no_check_in(): void
    {
        // Regression guard for the geoDistance filter specifically: the address
        // is ~222m north of the fix — inside the ~333m bbox prefilter (so the
        // bbox does NOT reject it), but outside the 160.934m proximity radius,
        // so ONLY geoDistance can exclude it. If the radius predicate regressed
        // this would wrongly record a check-in.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3020);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(0, ScoutCheckIn::query()->count());
    }

    #[Test]
    public function it_records_one_check_in_per_hour_when_parked_across_an_hour_boundary(): void
    {
        // Window [12:50, 13:05] spans an hour boundary; two fixes at the same
        // address in different hours must yield two rows (one per hour bucket).
        $this->travelTo(now()->setTime(13, 5));

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, now()->setTime(12, 59));
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, now()->setTime(13, 2));

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $rows = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->orderBy('hour_bucket')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(now()->setTime(12, 0)->toDateTimeString(), $rows[0]->hour_bucket->toDateTimeString());
        $this->assertSame(now()->setTime(13, 0)->toDateTimeString(), $rows[1]->hour_bucket->toDateTimeString());
    }

    #[Test]
    public function an_address_on_both_a_direct_and_staging_order_records_one_deterministic_direct_row(): void
    {
        // A single household address on BOTH an open Direct order (own org) and
        // an open Staging order (approved partner). Dedup is per (kit, address,
        // hour), so exactly one row — deterministically labelled Direct (the
        // lower is_staged / hotlist id), never flipping between runs.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $directId = $this->insertHotlist();

        $stagingOrg = Org::factory()->create();
        ApprovalList::query()->create([
            'agent_org_id' => $this->org->id,
            'staging_org_id' => $stagingOrg->id,
        ]);
        $stagingId = $this->insertHotlist([
            'org_id' => $stagingOrg->id,
            'is_staged' => true,
        ]);

        $addressId = 5900;
        $this->insertOpenAssignmentAddress($directId, -97.7, 30.3, addressId: $addressId);
        $this->insertOpenAssignmentAddress($stagingId, -97.7, 30.3, orgId: $stagingOrg->id, isStaged: true, addressId: $addressId);

        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $rows = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]->is_staged);
        $this->assertSame($directId, $rows[0]->hotlist_id);
    }

    #[Test]
    public function a_non_scout_user_produces_no_check_ins(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        setPermissionsTeamId($this->driver->org_id);
        $this->driver->revokePermissionTo('clarity-scout');

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(0, ScoutCheckIn::query()->count());
    }

    #[Test]
    public function it_no_ops_when_clarity_scout_is_disabled(): void
    {
        config()->set('app.experimental.clarity_scout', false);

        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(0, ScoutCheckIn::query()->count());
    }

    #[Test]
    public function non_utc_driver_records_a_utc_hour_bucket(): void
    {
        // Chicago is UTC-5 in August, so 02:30 UTC is 21:30 the PREVIOUS day
        // locally. hour_bucket must reflect the UTC hour (the sliding window
        // is UTC-only), never the driver's local hour.
        $this->driver->update(['dti' => ['timeZone' => 'America/Chicago']]);

        $this->travelTo(Carbon::parse('2026-08-10 02:30:00', 'UTC'));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $row = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($hotlistId, $row->hotlist_id);
        $this->assertSame('2026-08-10 02:00:00', $row->hour_bucket->toDateTimeString());
    }

    #[Test]
    public function processes_multiple_drivers_and_skips_a_kitless_one(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $directId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($directId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $secondMapping = 'KIT-TEST-2313-B';
        $secondKit = Kit::factory()->forOrg($this->org)->create([
            'kit_mapping' => strtolower($secondMapping),
        ]);
        $secondDriver = User::factory()->forOrg($this->org)->forKit($secondKit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);
        setPermissionsTeamId($secondDriver->org_id);
        $secondDriver->givePermissionTo('clarity-scout');

        $secondHotlistId = $this->insertHotlist();
        // Far enough from the first kit's fix that neither kit's bbox
        // prefilter can pick up the other kit's address.
        $this->insertOpenAssignmentAddress($secondHotlistId, -96.8, 32.8);
        $this->insertScPosition($secondMapping, -96.8, 32.8, $fixAt);

        $kitlessKit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => '']);
        $kitlessDriver = User::factory()->forOrg($this->org)->forKit($kitlessKit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);
        setPermissionsTeamId($kitlessDriver->org_id);
        $kitlessDriver->givePermissionTo('clarity-scout');

        $this->artisan('scout:record-check-ins')
            ->expectsOutputToContain('skipped 1')
            ->assertSuccessful();

        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
        $this->assertSame($directId, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->value('hotlist_id'));

        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $secondKit->id)->count());
        $this->assertSame($secondHotlistId, ScoutCheckIn::query()->where('kit_id', $secondKit->id)->value('hotlist_id'));

        $this->assertSame(2, ScoutCheckIn::query()->count());
    }

    #[Test]
    public function clickhouse_unreachable_degrades_gracefully_without_crashing(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $originalConnection = config('database.connections.clarity_clickhouse');

        try {
            config([
                'database.connections.clarity_clickhouse.host' => '127.0.0.1',
                'database.connections.clarity_clickhouse.port' => 1,
            ]);
            DB::purge('clarity_clickhouse');

            // ::handle() throws on a dead connection; the per-kit try/catch
            // records a skip and the run still succeeds (AC7).
            $this->artisan('scout:record-check-ins')
                ->assertSuccessful();

            $this->assertSame(0, ScoutCheckIn::query()->count());
        } finally {
            config(['database.connections.clarity_clickhouse' => $originalConnection]);
            DB::purge('clarity_clickhouse');
        }
    }

    #[Test]
    public function it_logs_a_completion_summary_for_observability(): void
    {
        Log::spy();

        $this->travelTo(now()->setTime(12, 0));

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return str_contains($message, 'record-check-ins')
                && array_key_exists('duration_ms', $context)
                && array_key_exists('recorded', $context)
                && array_key_exists('skipped', $context);
        });
    }

    #[Test]
    public function a_newly_opened_order_is_visible_on_the_next_cycle(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();
        $this->assertSame(0, ScoutCheckIn::query()->count());

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);

        $this->artisan('scout:record-check-ins')->assertSuccessful();
        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
        $this->assertSame($hotlistId, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->value('hotlist_id'));
    }

    #[Test]
    public function a_per_kit_failure_is_skipped_and_logged_without_killing_the_run(): void
    {
        Log::spy();

        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $originalConnection = config('database.connections.clarity_clickhouse');
        $originalEnv = config('app.env');

        try {
            config([
                'app.env' => 'local',
                'database.connections.clarity_clickhouse.host' => '127.0.0.1',
                'database.connections.clarity_clickhouse.port' => 1,
            ]);
            DB::purge('clarity_clickhouse');

            $this->artisan('scout:record-check-ins')
                ->expectsOutputToContain(sprintf('Skipped kit %d', $this->kit->id))
                ->expectsOutputToContain('skipped 1')
                ->assertSuccessful();

            $this->assertSame(0, ScoutCheckIn::query()->count());

            Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
                return str_contains($message, 'kit failed')
                    && ($context['kit_id'] ?? null) === $this->kit->id;
            });
        } finally {
            config([
                'app.env' => $originalEnv,
                'database.connections.clarity_clickhouse' => $originalConnection,
            ]);
            DB::purge('clarity_clickhouse');
        }
    }

    #[Test]
    public function the_since_option_widens_the_lookback_window(): void
    {
        // A fix two hours old is outside the default now-15min window but
        // inside an explicit --since bound.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subHours(2);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();
        $this->assertSame(0, ScoutCheckIn::query()->count());

        $this->artisan('scout:record-check-ins', [
            '--since' => now()->subHours(3)->toDateTimeString(),
        ])->assertSuccessful();

        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
        $this->assertSame($hotlistId, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->value('hotlist_id'));
    }

    #[Test]
    public function it_records_multiple_distinct_geo_addresses_in_one_cycle(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        // ~14.7-29.4km apart -- genuinely distinct geos, so each stays its
        // own check-in.
        $spots = [[-97.70, 30.30], [-97.80, 30.40], [-97.90, 30.50]];

        foreach ($spots as $i => [$lng, $lat]) {
            $hotlistId = $this->insertHotlist(['plate' => sprintf('ABC%04d', $i)]);
            $this->insertOpenAssignmentAddress($hotlistId, $lng, $lat);
            $this->insertScPosition(self::MAPPING, $lng, $lat, $fixAt->copy()->addSeconds($i));
        }

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(3, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Test]
    public function it_collapses_colocated_addresses_into_one_check_in(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        // Three distinct Direct address_ids at the IDENTICAL coordinate --
        // one physical visit must produce one check-in, not three. Seeded out of
        // ascending order so the assertion pins the lowest-address_id election
        // rather than insertion order.
        foreach ([7002, 7000, 7001] as $i => $addressId) {
            $hotlistId = $this->insertHotlist(['plate' => sprintf('GEO%04d', $i)]);
            $this->insertOpenAssignmentAddress($hotlistId, -97.70, 30.30, addressId: $addressId);
        }

        $this->insertScPosition(self::MAPPING, -97.70, 30.30, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $checkIns = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();

        $this->assertCount(1, $checkIns);
        $this->assertSame(7000, $checkIns->first()->address_id);
    }

    #[Test]
    public function a_production_clickhouse_failure_emits_a_failed_signal_not_an_empty_completion(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        Log::spy();
        Exceptions::fake();

        $originalConnection = config('database.connections.clarity_clickhouse');
        $originalEnv = config('app.env');

        try {
            config([
                'app.env' => 'production',
                'database.connections.clarity_clickhouse.host' => '127.0.0.1',
                'database.connections.clarity_clickhouse.port' => 1,
            ]);
            DB::purge('clarity_clickhouse');

            $this->artisan('scout:record-check-ins')
                ->expectsOutputToContain(sprintf('Skipped kit %d', $this->kit->id))
                ->assertSuccessful();

            $this->assertSame(0, ScoutCheckIn::query()->count());

            Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
                return str_contains($message, 'kit failed')
                    && ($context['kit_id'] ?? null) === $this->kit->id;
            });

            $this->assertNotEmpty(
                Exceptions::reported(),
                'a failed CH call must report() so it is distinguishable from an empty cycle',
            );
        } finally {
            config([
                'app.env' => $originalEnv,
                'database.connections.clarity_clickhouse' => $originalConnection,
            ]);
            DB::purge('clarity_clickhouse');
        }
    }

    #[Test]
    public function a_full_slot_pool_no_ops_regardless_of_how_long_a_cycle_would_take(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7, 30.3);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $slots = (int) config('scout.check_ins.max_concurrent_slots');
        $leaseMinutes = (int) config('scout.check_ins.slot_lease_minutes');
        $lookbackMinutes = (int) config('scout.check_ins.lookback_minutes');

        $this->assertGreaterThan(0, $slots);
        $this->assertLessThanOrEqual($lookbackMinutes, $leaseMinutes);

        $held = [];

        try {
            for ($i = 0; $i < $slots; $i++) {
                $key = 'scout:record-check-ins:slot:'.$i;
                $this->assertTrue(
                    Cache::add($key, 'test-holder', $leaseMinutes * 60),
                    "failed to hold slot {$i}",
                );
                $held[] = $key;
            }

            $this->artisan('scout:record-check-ins')
                ->expectsOutputToContain('No concurrency slot available')
                ->assertSuccessful();

            $this->assertSame(0, ScoutCheckIn::query()->count());
        } finally {
            foreach ($held as $key) {
                Cache::forget($key);
            }
        }

        $this->artisan('scout:record-check-ins')->assertSuccessful();
        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Test]
    public function a_non_positive_slot_count_warns_and_skips_rather_than_looking_busy(): void
    {
        config(['scout.check_ins.max_concurrent_slots' => 0]);

        Log::spy();
        Exceptions::fake();

        $this->artisan('scout:record-check-ins')
            ->expectsOutputToContain('Concurrency slots misconfigured')
            ->assertSuccessful();

        $this->assertSame(0, ScoutCheckIn::query()->count());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return str_contains($message, 'concurrency slots misconfigured');
        });

        Exceptions::assertReported(\RuntimeException::class);
    }

    #[Test]
    public function a_stale_refreshed_at_does_not_skip_the_cycle(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress(
            $hotlistId,
            -97.7,
            30.3,
            refreshedAt: now()->subMinutes(50),
        );
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Test]
    public function an_empty_book_records_nothing_without_a_counted_failure(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->truncateConfiguredClickhouseTables();

        Log::spy();
        Exceptions::fake();

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $this->assertSame(0, ScoutCheckIn::query()->count());
        Exceptions::assertNothingReported();
        Log::shouldNotHaveReceived('warning');
    }

    // ---------------------------------------------------------------------
    // AC2 -- flip mechanisms. Each runs the command twice in the same
    // hour_bucket, mutating clarity.scout_open_addresses between the two
    // runs, and pins that attribution is frozen at first sighting (Dalton,
    // 2026-08-25): the second run's winner change must not add a spurious
    // row or rewrite the already-persisted one.
    // ---------------------------------------------------------------------

    #[Test]
    public function mechanism_1_a_lower_address_id_opening_at_the_same_geo_does_not_flip_the_winner(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $firstHotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($firstHotlistId, -97.7, 30.3, addressId: 6500);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $original = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->first();
        $this->assertNotNull($original);
        $this->assertSame(6500, $original->address_id);
        $originalMatchedAt = $original->matched_at->toDateTimeString();

        // A new Direct order opens at the SAME geo with a LOWER address_id --
        // the election would elect it going forward, but the row is already
        // frozen and collides on (kit, addr_lng, addr_lat, hour, is_staged).
        $secondHotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($secondHotlistId, -97.7, 30.3, addressId: 6499);

        // Positive control: prove the mutation actually landed and the
        // election genuinely flipped, BEFORE re-running the command --
        // otherwise a run 2 that silently matched nothing (a lookback,
        // bbox, or book-read regression) would pass the assertions below
        // just as well as a correctly-ignored flip.
        $reElected = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => $this->kit->kit_mapping,
            'org_id' => $this->org->id,
            'staging_org_ids' => [],
            'start' => now()->subMinutes(15)->toDateTimeString(),
            'end' => now()->toDateTimeString(),
        ]);
        $this->assertCount(1, $reElected);
        $this->assertSame(6499, $reElected[0]['address_id']);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $rows = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(6500, $rows[0]->address_id);
        $this->assertSame($originalMatchedAt, $rows[0]->matched_at->toDateTimeString());
    }

    #[Test]
    public function mechanism_2_the_winning_orders_closing_does_not_flip_the_winner(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $lowHotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($lowHotlistId, -97.7, 30.3, addressId: 6600);
        $highHotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($highHotlistId, -97.7, 30.3, addressId: 6601);
        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $original = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->first();
        $this->assertNotNull($original);
        $this->assertSame(6600, $original->address_id);
        $originalMatchedAt = $original->matched_at->toDateTimeString();

        // The winning order closes -- its book row drops out of the next
        // rebuild, so the next-lowest address_id (6601) would win the
        // election, but the frozen row must not be replaced by it.
        $this->deleteOpenAssignmentAddress(6600);

        // Positive control: prove the mutation actually landed and the
        // election genuinely flipped, BEFORE re-running the command --
        // otherwise a run 2 that silently matched nothing (a lookback,
        // bbox, or book-read regression) would pass the assertions below
        // just as well as a correctly-ignored flip.
        $reElected = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => $this->kit->kit_mapping,
            'org_id' => $this->org->id,
            'staging_org_ids' => [],
            'start' => now()->subMinutes(15)->toDateTimeString(),
            'end' => now()->toDateTimeString(),
        ]);
        $this->assertCount(1, $reElected);
        $this->assertSame(6601, $reElected[0]['address_id']);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $rows = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(6600, $rows[0]->address_id);
        $this->assertSame($originalMatchedAt, $rows[0]->matched_at->toDateTimeString());
    }

    #[Test]
    public function mechanism_3_an_address_moving_sides_between_rebuilds_preserves_the_frozen_staged_row_and_adds_a_direct_one(): void
    {
        // TEMPORAL, not simultaneous dual-listing (flip-risk-measurement.md
        // §3: 0 of 4,519,947 book address_ids carry both is_staged values).
        // The winning Staging book row for address 8000 is deleted and the
        // SAME address_id reappears fresh on a Direct hotlist at the same
        // geo -- modelling an address moving sides across a 15-minute book
        // rebuild, not one address on both sides at once.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $stagingOrg = Org::factory()->create();
        ApprovalList::query()->create([
            'agent_org_id' => $this->org->id,
            'staging_org_id' => $stagingOrg->id,
        ]);

        $stagingWinnerHotlistId = $this->insertHotlist([
            'org_id' => $stagingOrg->id,
            'is_staged' => true,
        ]);
        $this->insertOpenAssignmentAddress($stagingWinnerHotlistId, -97.7, 30.3, orgId: $stagingOrg->id, isStaged: true, addressId: 8000);

        $stagingOtherHotlistId = $this->insertHotlist([
            'org_id' => $stagingOrg->id,
            'is_staged' => true,
        ]);
        $this->insertOpenAssignmentAddress($stagingOtherHotlistId, -97.7, 30.3, orgId: $stagingOrg->id, isStaged: true, addressId: 8001);

        $this->insertScPosition(self::MAPPING, -97.7, 30.3, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $run1 = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->first();
        $this->assertNotNull($run1);
        $this->assertTrue($run1->is_staged);
        $this->assertSame(8000, $run1->address_id);
        $run1MatchedAt = $run1->matched_at->toDateTimeString();

        // 8000's Staging book row is gone; 8000 reappears fresh on a Direct
        // hotlist at the same geo. 8001 is still on the Staging book, so it
        // would now win that side's election -- but that side's row already
        // exists and is frozen.
        $this->deleteOpenAssignmentAddress(8000);
        $directHotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($directHotlistId, -97.7, 30.3, addressId: 8000);

        // Positive control (matching mechanism_1/mechanism_2): prove the book
        // mutation actually landed and the election really moved sides, so a
        // run-2 no-op cannot pass this test by leaving run 1's single row alone.
        $elected = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::MAPPING,
            'org_id' => $this->org->id,
            'staging_org_ids' => [$stagingOrg->id],
            'start' => now()->subMinutes(15)->utc()->toDateTimeString(),
            'end' => now()->utc()->toDateTimeString(),
        ]);
        $electedBySide = collect($elected)->keyBy(fn (array $m): string => $m['is_staged'] ? 'staged' : 'direct');
        $this->assertTrue($electedBySide->has('direct'), '8000 should now be elected on the Direct side');
        $this->assertSame(8000, $electedBySide['direct']['address_id']);
        $this->assertTrue($electedBySide->has('staged'), 'the Staging side should still elect a winner');
        $this->assertSame(8001, $electedBySide['staged']['address_id'], 'the Staging election should have moved to 8001');

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $rows = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();
        $this->assertCount(2, $rows);

        $direct = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->where('is_staged', false)->get();
        $staged = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->where('is_staged', true)->get();
        $this->assertCount(1, $direct);
        $this->assertCount(1, $staged);

        // The Staging row was NOT rewritten by the new (now-losing) match --
        // it still carries run 1's matched_at.
        $this->assertSame($run1MatchedAt, $staged->first()->matched_at->toDateTimeString());

        // Both rows carry address_id 8000. This looks odd -- it pins the
        // explicitly accepted consequence of freezing attribution (Dalton,
        // 2026-08-25): the Staging row keeps naming 8000 (frozen at run 1)
        // while the new Direct row also names 8000 (the address that just
        // opened there), which is what lets DEV-1756's derived
        // `COUNT(*) WHERE address_id = ?` report two check-ins for one
        // address at one place in one hour. Pinning it means a future
        // change that silently alters that behaviour fails a test instead
        // of surprising someone. A count-only assertion, or one that only
        // checks the new row's label, would also pass a broken design that
        // produced two STAGING rows and no Direct row at all -- the checks
        // above on is_staged counts rule that out.
        $this->assertSame(8000, $direct->first()->address_id);
        $this->assertSame(8000, $staged->first()->address_id);
    }

    // ---------------------------------------------------------------------
    // t5 -- Postgres-layer pins: float precision, AC4 grain regression,
    // geo provenance through to the stored row, and constraint directness.
    // ---------------------------------------------------------------------

    #[Test]
    public function coordinates_roughly_a_meter_apart_stay_distinct_and_re_running_is_idempotent(): void
    {
        // Two DISTINCT book addresses ~1 m apart (1e-5 degrees) stay two
        // rows, and re-running against an unchanged book adds none. This is
        // a determinism/idempotency guard, not a precision-boundary probe:
        // per geo-key-float-precision.md, the key's soundness rests on the
        // PDO float->string conversion being a DETERMINISTIC function of the
        // input (the same geo always stores the same 14-significant-digit
        // value), not on it surviving without loss. The one failure mode
        // determinism does NOT cover -- two ClickHouse-distinct coordinates
        // truncating to the SAME 14-digit string ("over-collapse") -- only
        // bites at ~1e-12 degrees, seven orders of magnitude finer than the
        // 1e-5 separation seeded here; nothing in the book can realistically
        // reach that boundary, so this test does not (and cannot
        // meaningfully) probe it.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $firstHotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($firstHotlistId, -97.70, 30.30, addressId: 6700);
        $secondHotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($secondHotlistId, -97.70001, 30.30, addressId: 6701);

        $this->insertScPosition(self::MAPPING, -97.70, 30.30, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();
        $this->assertSame(2, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());

        $this->artisan('scout:record-check-ins')->assertSuccessful();
        $this->assertSame(2, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Test]
    public function several_co_located_addresses_per_side_still_collapse_to_one_row_per_side(): void
    {
        // AC4 regression guard: N co-located Direct addresses PLUS a
        // co-located Staging address must still yield exactly one row per
        // is_staged side, not one row per address_id and not one row total.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        foreach ([7102, 7100, 7101] as $i => $addressId) {
            $hotlistId = $this->insertHotlist(['plate' => sprintf('DIR%04d', $i)]);
            $this->insertOpenAssignmentAddress($hotlistId, -97.70, 30.30, addressId: $addressId);
        }

        $stagingOrg = Org::factory()->create();
        ApprovalList::query()->create([
            'agent_org_id' => $this->org->id,
            'staging_org_id' => $stagingOrg->id,
        ]);
        $stagingHotlistId = $this->insertHotlist([
            'org_id' => $stagingOrg->id,
            'is_staged' => true,
        ]);
        $this->insertOpenAssignmentAddress($stagingHotlistId, -97.70, 30.30, orgId: $stagingOrg->id, isStaged: true, addressId: 7200);

        $this->insertScPosition(self::MAPPING, -97.70, 30.30, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $rows = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();
        $this->assertCount(2, $rows);

        $direct = $rows->where('is_staged', false);
        $staged = $rows->where('is_staged', true);
        $this->assertCount(1, $direct);
        $this->assertCount(1, $staged);
        $this->assertSame(7100, $direct->first()->address_id);
        $this->assertSame(7200, $staged->first()->address_id);
    }

    #[Test]
    public function persisted_rows_pin_addr_lat_lng_to_the_book_address_and_lat_lng_to_the_fix(): void
    {
        // t1 pinned this at the query layer; this pins it through to the
        // stored row. The book address and the fix are deliberately at
        // different (but still in-radius) points so a swap of the two
        // coordinate pairs would fail this assertion.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, -97.7000, 30.3000);
        // ~36m from the book address -- within the 160.934m radius.
        $this->insertScPosition(self::MAPPING, -97.7003, 30.3002, $fixAt);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $row = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->first();
        $this->assertNotNull($row);

        // Delta matched to the query-layer twin in ScoutCheckInMatchQueryParityTest:
        // far tighter than the ~0.0002-0.0003 deg book/fix separation, so swapping
        // the two pairs fails loudly rather than sliding under the tolerance.
        $this->assertEqualsWithDelta(-97.7000, $row->addr_lng, 1e-6);
        $this->assertEqualsWithDelta(30.3000, $row->addr_lat, 1e-6);
        $this->assertEqualsWithDelta(-97.7003, $row->lng, 1e-6);
        $this->assertEqualsWithDelta(30.3002, $row->lat, 1e-6);
    }

    #[Test]
    public function the_unique_constraint_dedupes_on_the_geo_hour_side_key_not_address_id(): void
    {
        // Exercises scout_check_ins_geo_hour_side_unique directly at the
        // Postgres layer: a second insertOrIgnore of the same (kit_id,
        // addr_lng, addr_lat, hour_bucket, is_staged) tuple -- even with a
        // different address_id/hotlist_id -- must not add a row, while
        // flipping ONLY is_staged is a different key and must add one.
        $this->travelTo(now()->setTime(12, 0));
        $matchedAt = now()->subMinutes(5);
        $hourBucket = $matchedAt->copy()->startOfHour();

        $row = [
            'kit_id' => $this->kit->id,
            'org_id' => $this->org->id,
            'hotlist_id' => 9100,
            'address_id' => 9200,
            'is_staged' => false,
            'matched_at' => $matchedAt->toDateTimeString(),
            'hour_bucket' => $hourBucket->toDateTimeString(),
            'lat' => 30.3,
            'lng' => -97.7,
            'addr_lat' => 30.3,
            'addr_lng' => -97.7,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        ScoutCheckIn::query()->insertOrIgnore([$row]);
        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());

        $duplicateGeoRow = $row;
        $duplicateGeoRow['hotlist_id'] = 9101;
        $duplicateGeoRow['address_id'] = 9201;

        ScoutCheckIn::query()->insertOrIgnore([$duplicateGeoRow]);
        $this->assertSame(1, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());

        $otherSideRow = $row;
        $otherSideRow['is_staged'] = true;
        $otherSideRow['hotlist_id'] = 9102;
        $otherSideRow['address_id'] = 9202;

        ScoutCheckIn::query()->insertOrIgnore([$otherSideRow]);
        $this->assertSame(2, ScoutCheckIn::query()->where('kit_id', $this->kit->id)->count());
    }

    #[Test]
    public function a_kit_reassigned_between_orgs_within_an_hour_records_one_check_in_per_org(): void
    {
        // Regression pin for `org_id` in scout_check_ins_geo_hour_side_unique.
        // NOT simultaneous sharing -- kit_settings keys on kit_id, so a kit has
        // exactly one driver and one org per cycle. The reachable path is
        // SEQUENTIAL: the kit is reassigned to another org's driver inside the
        // same hour, so a later cycle stamps a different org_id for the same
        // (kit, geo, hour). Without org_id in the key the second org's row
        // collides on (kit_id, addr_lng, addr_lat, hour_bucket, is_staged) and
        // insertOrIgnore silently drops it.
        //
        // `kit_settings.kit_id` is that table's PRIMARY KEY and `user_id` is
        // UNIQUE, so a kit can only be assigned to one driver at a time --
        // two drivers cannot hold the same kit_settings row simultaneously.
        // The reachable form of "shared kit, two orgs" is therefore the SAME
        // kit reassigned between two orgs' drivers over time: run 1 records
        // org A's check-in, the kit is handed to org B's driver, and run 2
        // (same frozen hour_bucket) records org B's check-in for the SAME
        // kit_id. Without org_id in the key, run 2's insertOrIgnore would
        // collide with run 1's row and be silently dropped.
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        $orgB = Org::factory()->create();
        $this->makeOrgPro($orgB);
        $driverB = User::factory()->forOrg($orgB)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);
        setPermissionsTeamId($driverB->org_id);
        $driverB->givePermissionTo('clarity-scout');

        $hotlistA = $this->insertHotlist();
        $addressA = $this->insertOpenAssignmentAddress($hotlistA, -97.7, 30.3, addressId: 9300);

        $hotlistB = $this->insertHotlist(['org_id' => $orgB->id]);
        $addressB = $this->insertOpenAssignmentAddress($hotlistB, -97.7, 30.3, orgId: $orgB->id, addressId: 9301);

        $this->insertScPosition($this->kit->kit_mapping, -97.7, 30.3, $fixAt);

        // Run 1 -- kit is assigned to org A's driver (setUp's default).
        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $afterFirstRun = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();
        $this->assertCount(1, $afterFirstRun);
        $this->assertSame($this->org->id, $afterFirstRun->first()->org_id);
        $this->assertSame($addressA, $afterFirstRun->first()->address_id);

        // The SAME kit changes hands to org B's driver. kit_settings.kit_id
        // is a primary key, so this REPLACES org A's assignment rather than
        // adding a second one -- org A's driver drops out of
        // scoutEnabledDrivers() from here on. The clock stays frozen, so
        // run 2 lands in the same hour_bucket as run 1.
        KitSettings::query()->where('kit_id', $this->kit->id)->update(['user_id' => $driverB->id]);

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        $rows = ScoutCheckIn::query()->where('kit_id', $this->kit->id)->get();
        $this->assertCount(2, $rows);

        $rowA = $rows->firstWhere('org_id', $this->org->id);
        $rowB = $rows->firstWhere('org_id', $orgB->id);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertSame($addressA, $rowA->address_id);
        $this->assertSame($addressB, $rowB->address_id);
        $this->assertEqualsWithDelta($rowA->addr_lng, $rowB->addr_lng, 1e-6);
        $this->assertEqualsWithDelta($rowA->addr_lat, $rowB->addr_lat, 1e-6);
    }
}
