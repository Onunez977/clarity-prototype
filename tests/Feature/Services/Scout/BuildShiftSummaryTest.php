<?php

namespace Tests\Feature\Services\Scout;

use App\Enums\ShiftEndTypeEnum;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Services\Scout\BuildShiftSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * Exercises BuildShiftSummary against real ClickHouse: total miles (sc_positions),
 * total scans (scans_hot/cold), and total hits (hits_cold).
 *
 * The PG ⋈ zones per-zone breakdown is covered by seeding `public.scans` rows
 * directly on the `platform` connection (via `insertPgScan()`), rather than
 * `Scan::factory()` (which writes on the `zod` connection, in a separate
 * RefreshDatabase transaction the platform-connection join can't see).
 */
class BuildShiftSummaryTest extends TestCase
{
    use RequiresClickhouse;

    private Org $org;

    private Kit $kit;

    private User $user;

    private int $scanHotId = 1;

    private int $scanColdId = 1;

    private int $hitColdId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time so the scans_hot vs scans_cold (>90d) boundary is deterministic.
        // Anchored to the real clock rather than a fixed date: clarity.scans_hot and
        // clarity.hits_hot carry a 90-day DELETE TTL, so a hardcoded anchor eventually
        // ages past it, ClickHouse drops the fixtures on insert, and the counts read
        // back zero with nothing in the app having changed.
        Carbon::setTestNow(Carbon::now()->startOfHour());

        // No Mapbox token → mapUrl resolves to null and no HTTP call is made.
        config()->set('services.mapbox.token', null);

        $this->org = Org::factory()->create();
        $this->kit = Kit::factory()->forOrg($this->org)->create(['name' => 'Unit 7']);
        $this->user = User::factory()->forOrg($this->org)->forKit($this->kit)->create([
            'name' => 'Dana Driver',
            'email' => 'dana@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_builds_a_full_summary_for_a_shift_with_activity(): void
    {
        $shift = $this->shift(now()->subHours(2), now());

        // ClickHouse: GPS track (miles), total scans, total hits. Two fixes >3s
        // apart (so decimation keeps them distinct) and ~386ft apart — far enough
        // that the haversine distance survives the DTO's round(_, 1) as 0.1mi, yet
        // under the 500ft break threshold so they stay one segment.
        $this->insertScPosition([-97.7150, 30.3050], now()->subHour());
        $this->insertScPosition([-97.7142, 30.3058], now()->subHour()->addSeconds(5));
        $this->insertScanHot(['scanned_at' => now()->subHour()->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => now()->subHour()->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => now()->subHour()->toDateTimeString()]);
        $this->insertHitCold(['hit_created_at' => now()->subHour()->toDateTimeString()]);

        $summary = (new BuildShiftSummary)->forShift($shift);

        $this->assertSame($shift->id, $summary->shiftId);
        $this->assertSame('Unit 7', $summary->kitName);
        $this->assertSame('Dana Driver', $summary->driverName);
        $this->assertSame('dana@example.com', $summary->driverEmail);
        $this->assertSame(3, $summary->totalScans);
        $this->assertSame(1, $summary->totalHits);
        $this->assertGreaterThan(0.0, $summary->totalMiles);
        $this->assertNull($summary->mapUrl);
        $this->assertTrue($summary->hasActivity());
    }

    #[Test]
    public function it_returns_an_empty_summary_for_a_zero_activity_shift(): void
    {
        $shift = $this->shift(now()->subHours(2), now());

        $summary = (new BuildShiftSummary)->forShift($shift);

        $this->assertSame('Unit 7', $summary->kitName);
        $this->assertSame('Dana Driver', $summary->driverName);
        $this->assertSame(0, $summary->totalScans);
        $this->assertSame(0, $summary->totalHits);
        $this->assertSame(0.0, $summary->totalMiles);
        $this->assertSame([], $summary->zones);
        $this->assertNull($summary->mapUrl);
        $this->assertFalse($summary->hasActivity());
    }

    #[Test]
    public function it_does_not_mutate_the_shift_timestamps_while_building(): void
    {
        // The window is converted to UTC on copies for the ClickHouse/PG reads, so
        // the shift's own Carbons (which feed the summary email) keep their original
        // timezone. Guards against a regression where Carbon::utc() mutates in place
        // and the zone-breakdown window / displayed times silently shift.
        $shift = KitShift::factory()->forKit($this->kit)->create([
            'started_at' => Carbon::parse('2026-05-01 06:00:00', 'America/Chicago'),
            'ended_at' => Carbon::parse('2026-05-01 09:00:00', 'America/Chicago'),
            'end_type' => ShiftEndTypeEnum::Confirmed,
        ]);

        $startedBefore = $shift->started_at->format('Y-m-d H:i:s P');
        $endedBefore = $shift->ended_at->format('Y-m-d H:i:s P');

        (new BuildShiftSummary)->forShift($shift);

        $this->assertSame($startedBefore, $shift->started_at->format('Y-m-d H:i:s P'));
        $this->assertSame($endedBefore, $shift->ended_at->format('Y-m-d H:i:s P'));
    }

    #[Test]
    public function it_counts_scans_from_cold_storage_for_shifts_older_than_ninety_days(): void
    {
        // Shift 120 days ago → countScans() reads scans_cold, not scans_hot.
        $shift = $this->shift(now()->subDays(120), now()->subDays(120)->addHours(3));

        $this->insertScanCold(['scanned_at' => now()->subDays(120)->addHour()->toDateTimeString()]);
        $this->insertScanCold(['scanned_at' => now()->subDays(120)->addHour()->toDateTimeString()]);
        // A hot row inside the same window must NOT be counted for an old shift.
        $this->insertScanHot(['scanned_at' => now()->subDays(120)->addHour()->toDateTimeString()]);

        $summary = (new BuildShiftSummary)->forShift($shift);

        $this->assertSame(2, $summary->totalScans);
    }

    #[Test]
    public function it_should_degrade_to_a_zeros_summary_when_clickhouse_is_unavailable(): void
    {
        // Audit finding TC-S6 (medium): countScans()/countHits()/pullTrips() have no
        // try/catch, so a ClickHouse outage throws and the summary job fails instead of
        // sending a zeros email. This test documents the expected graceful behavior;
        // flip it to an assertion once the resilience fix lands in BuildShiftSummary.
        $this->markTestIncomplete('BuildShiftSummary does not yet degrade gracefully on a ClickHouse outage (TC-S6).');
    }

    #[Test]
    public function it_attributes_scans_to_the_zone_containing_them_via_a_bounded_shift_scans_cte(): void
    {
        // DEV-2325 pin: the PG scans ⋈ zones query must bound the candidate
        // scan set (kit_id + shift window) in a materialized CTE BEFORE the
        // ST_Contains spatial join runs, so it can't degrade into an unbounded
        // scan of the partitioned public.scans table.
        $shift = $this->shift(now()->subHours(2), now());

        // Square zone anchored at (-97.7150, 30.3050), 0.02° (~1.4mi) per side.
        $zone = Zone::factory()->forOrg($this->org)->atLocation(-97.7150, 30.3050)->withGeometry()->create();

        // Inside the zone polygon, within the shift window.
        $this->insertPgScan([-97.7145, 30.3055], now()->subHour());
        // Hidden scan (is_visible = false) inside the zone — the raw-SQL breakdown
        // intentionally bypasses the ShowVisibleScan global scope, so it must still
        // be counted (the fix must not silently add an is_visible filter).
        $this->insertPgScan([-97.7148, 30.3052], now()->subHour()->addMinutes(5), false);
        // Well outside any zone, within the shift window — must not be attributed.
        $this->insertPgScan([-96.0000, 32.0000], now()->subHour()->addMinutes(10));

        DB::connection('platform')->enableQueryLog();

        $summary = (new BuildShiftSummary)->forShift($shift);

        $zoneQuery = collect(DB::connection('platform')->getQueryLog())
            ->first(fn (array $entry) => str_contains(strtolower($entry['query']), 'shift_scans'));

        DB::connection('platform')->flushQueryLog();
        DB::connection('platform')->disableQueryLog();

        $this->assertNotNull($zoneQuery, 'Expected the zone-breakdown query to run on the platform connection with a shift_scans CTE.');
        $this->assertStringContainsString('materialized', strtolower((string) $zoneQuery['query']));

        $this->assertCount(1, $summary->zones);
        $this->assertSame($zone->id, $summary->zones[0]->id);
        // Both the visible and the hidden in-zone scan are counted (2), not just
        // the visible one — the breakdown deliberately ignores ShowVisibleScan.
        $this->assertSame(2, $summary->zones[0]->scans);
        $this->assertSame(0, $summary->zones[0]->hits);
    }

    #[Test]
    public function it_summarizes_only_the_last_eight_hours_of_a_long_open_shift(): void
    {
        $endedAt = now();
        $shift = $this->shift($endedAt->copy()->subDays(17), $endedAt);

        $zone = Zone::factory()->forOrg($this->org)->atLocation(-97.7150, 30.3050)->withGeometry()->create();

        $this->insertPgScan([-97.7145, 30.3055], $endedAt->copy()->subHours(10));
        $this->insertScanHot(['scanned_at' => $endedAt->copy()->subHours(10)->toDateTimeString()]);

        $this->insertPgScan([-97.7148, 30.3052], $endedAt->copy()->subHour());
        $this->insertScanHot(['scanned_at' => $endedAt->copy()->subHour()->toDateTimeString()]);

        DB::connection('platform')->enableQueryLog();

        $summary = (new BuildShiftSummary)->forShift($shift);

        $zoneQuery = collect(DB::connection('platform')->getQueryLog())
            ->first(fn (array $entry) => str_contains(strtolower($entry['query']), 'shift_scans'));

        DB::connection('platform')->flushQueryLog();
        DB::connection('platform')->disableQueryLog();

        $this->assertNotNull($zoneQuery);
        $windowStart = $endedAt->copy()->utc()->subHours(BuildShiftSummary::SUMMARY_WINDOW_HOURS);
        $this->assertSame($windowStart->toDateTimeString(), (string) $zoneQuery['bindings'][1]);
        $this->assertSame($endedAt->copy()->utc()->toDateTimeString(), (string) $zoneQuery['bindings'][2]);

        $this->assertSame(1, $summary->totalScans);
        $this->assertCount(1, $summary->zones);
        $this->assertSame($zone->id, $summary->zones[0]->id);
        $this->assertSame(1, $summary->zones[0]->scans);
        $this->assertTrue($summary->startedAt->equalTo($shift->started_at));
        $this->assertTrue($summary->endedAt->equalTo($shift->ended_at));
        $this->assertTrue($summary->isTruncated());
        $this->assertTrue($summary->windowStartedAt->equalTo($windowStart));
        $this->assertTrue($summary->windowEndedAt->equalTo($endedAt->copy()->utc()));
    }

    private function shift(Carbon $startedAt, Carbon $endedAt): KitShift
    {
        return KitShift::factory()->forKit($this->kit)->create([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'end_type' => ShiftEndTypeEnum::Confirmed,
        ]);
    }

    /** @param array{0: float, 1: float} $point [lng, lat] */
    private function insertScPosition(array $point, Carbon $at): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.sc_positions')->insert([
            'kit_mapping' => $this->kit->kit_mapping,
            'geo' => "({$point[0]}, {$point[1]})",
            'timestamp_utc' => $at->toDateTimeString(),
        ]);
    }

    private function insertScanHot(array $overrides = []): void
    {
        $this->insertScan('clarity.scans_hot', $this->scanHotId++, $overrides);
    }

    private function insertScanCold(array $overrides = []): void
    {
        $this->insertScan('clarity.scans_cold', 10000 + $this->scanColdId++, $overrides);
    }

    private function insertScan(string $table, int $id, array $overrides): void
    {
        DB::connection('clarity_clickhouse')->table($table)->insert(array_merge([
            'id' => $id,
            'kit_id' => $this->kit->id,
            'org_id' => $this->org->id,
            'ocr' => 'ABC1234',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => now()->subHour()->toDateTimeString(),
            'created_at' => now()->subHour()->toDateTimeString(),
            'lng' => 0.5,
            'lat' => 0.5,
            '_peerdb_is_deleted' => 0,
        ], $overrides));
    }

    /**
     * Seed a `public.scans` row directly on the `platform` connection (raw
     * SQL insert, not `Scan::factory()`) so it's visible to the raw-SQL
     * zone-breakdown query within the same test transaction.
     *
     * Triggers are disabled for the insert: `no_scans_in_illegal_states`
     * unconditionally acquires a lock on `tiger.state` while planning (even
     * though it's short-circuited away at runtime for most rows), and
     * `HasZodDatabase::setupHasZodDatabase()` holds an `AccessExclusiveLock`
     * on `tiger.state` (via an `ALTER TABLE`) for the whole test's
     * `zod`-connection transaction — a cross-connection insert that plans a
     * read of it here would deadlock. None of the scans triggers are
     * relevant to this fixture (no hotlist/overwatch matching, no
     * aggregate/metadata rollups needed).
     *
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertPgScan(array $point, Carbon $at, bool $isVisible = true): void
    {
        DB::connection('platform')->statement('ALTER TABLE public.scans DISABLE TRIGGER ALL');

        try {
            DB::connection('platform')->insert(
                'INSERT INTO public.scans (ocr, us_state, geo, kit_id, is_visible, scanned_at, created_at, camera_name, guid)
                 VALUES (?, ?, public.ST_SetSRID(public.ST_MakePoint(?, ?), 4326), ?, ?, ?, ?, ?, ?)',
                [
                    'ABC'.random_int(1000, 9999),
                    'TX',
                    $point[0],
                    $point[1],
                    $this->kit->id,
                    $isVisible,
                    $at->toDateTimeString(),
                    $at->toDateTimeString(),
                    'cam1',
                    (string) Str::uuid(),
                ]
            );
        } finally {
            DB::connection('platform')->statement('ALTER TABLE public.scans ENABLE TRIGGER ALL');
        }
    }

    private function insertHitCold(array $overrides = []): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')->insert(array_merge([
            'id' => $this->hitColdId++,
            'kit_id' => $this->kit->id,
            'kit_org_id' => $this->org->id,
            'hit_type' => 'L',
            'hit_created_at' => now()->subHour()->toDateTimeString(),
            '_peerdb_is_deleted' => 0,
        ], $overrides));
    }
}
