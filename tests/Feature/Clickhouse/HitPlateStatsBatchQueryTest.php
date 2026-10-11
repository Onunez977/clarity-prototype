<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Queries\HitPlateStatsBatchQuery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

// clarity.hits_cold carries NO TTL (only hits_hot's 90-day TTL rots fixtures),
// so these rows would survive unanchored — they're still pinned to a frozen
// now() below purely for deterministic month-key assertions.
#[Group('clickhouse')]
class HitPlateStatsBatchQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const ORG_ID = 642;

    private const OTHER_ORG_ID = 643;

    private int $hitSeq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();
    }

    public function test_handle_dedupes_repeated_ocr_within_the_same_month(): void
    {
        // A bare count() would return 3; uniqExact(ocr) must return 2.
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);
        $this->seedHit(['ocr' => 'AAA1111', 'hit_type' => 'L', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'AAA1111', 'hit_type' => 'L', 'hit_created_at' => $month->copy()->addHours(1)->toDateTimeString()]);
        $this->seedHit(['ocr' => 'BBB2222', 'hit_type' => 'L', 'hit_created_at' => $month->copy()->addHours(2)->toDateTimeString()]);

        $result = HitPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame(2, $result[$monthKey]['live_hit_count']);
    }

    public function test_handle_does_not_collapse_the_same_ocr_across_different_months(): void
    {
        $thisMonth = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);
        $lastMonth = now()->startOfMonth()->subMonths(1)->addDays(5)->setTime(10, 0, 0);

        $this->seedHit(['ocr' => 'CCC3333', 'hit_type' => 'L', 'hit_created_at' => $thisMonth->toDateTimeString()]);
        $this->seedHit(['ocr' => 'CCC3333', 'hit_type' => 'L', 'hit_created_at' => $lastMonth->toDateTimeString()]);

        $result = HitPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(2)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
        ]);

        $thisMonthKey = $thisMonth->format('Y-m');
        $lastMonthKey = $lastMonth->format('Y-m');

        $this->assertArrayHasKey($thisMonthKey, $result);
        $this->assertArrayHasKey($lastMonthKey, $result);
        $this->assertSame(1, $result[$thisMonthKey]['live_hit_count']);
        $this->assertSame(1, $result[$lastMonthKey]['live_hit_count']);
    }

    public function test_handle_scopes_by_kit_org_id_not_hotlist_org_id(): void
    {
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        // Belongs to ORG by kit_org_id, but hotlist_org_id points elsewhere.
        $this->seedHit([
            'ocr' => 'DDD4444',
            'hit_type' => 'L',
            'hit_created_at' => $month->toDateTimeString(),
            'kit_org_id' => self::ORG_ID,
            'hotlist_org_id' => self::OTHER_ORG_ID,
        ]);

        // Belongs to OTHER_ORG by kit_org_id, but hotlist_org_id points at ORG.
        $this->seedHit([
            'ocr' => 'EEE5555',
            'hit_type' => 'L',
            'hit_created_at' => $month->toDateTimeString(),
            'kit_org_id' => self::OTHER_ORG_ID,
            'hotlist_org_id' => self::ORG_ID,
        ]);

        $result = HitPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame(1, $result[$monthKey]['live_hit_count']);
    }

    public function test_handle_maps_each_hit_type_to_the_correct_key(): void
    {
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedHit(['ocr' => 'LLL0001', 'hit_type' => 'L', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'HHH0002', 'hit_type' => 'H', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'SSS0003', 'hit_type' => 'S', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'ZZZ0004', 'hit_type' => 'Z', 'hit_created_at' => $month->toDateTimeString()]);

        // Undocumented/unsupported types must land in NONE of the four buckets.
        $this->seedHit(['ocr' => 'FFF0005', 'hit_type' => 'F', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'MMM0006', 'hit_type' => 'M', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'PPP0007', 'hit_type' => 'P', 'hit_created_at' => $month->toDateTimeString()]);

        $result = HitPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame([
            'live_hit_count' => 1,
            'historical_hit_count' => 1,
            'staging_live_hit_count' => 1,
            'staging_historical_hit_count' => 1,
        ], $result[$monthKey]);
    }

    public function test_handle_applies_final_replacing_merge_tree_dedup(): void
    {
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);
        $id = $this->hitSeq++;

        // Same id/hotlist_org_id/hit_created_at (the table's sort key) with two
        // _version rows for a stale ocr, plus a soft-deleted row that must not count.
        $this->seedHit([
            'id' => $id,
            'ocr' => 'STALE001',
            'hit_type' => 'L',
            'hit_created_at' => $month->toDateTimeString(),
            '_version' => 1,
        ]);
        $this->seedHit([
            'id' => $id,
            'ocr' => 'FRESH001',
            'hit_type' => 'L',
            'hit_created_at' => $month->toDateTimeString(),
            '_version' => 2,
        ]);

        $deletedId = $this->hitSeq++;
        $this->seedHit([
            'id' => $deletedId,
            'ocr' => 'GONE0001',
            'hit_type' => 'L',
            'hit_created_at' => $month->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 1,
        ]);

        $result = HitPlateStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->subMonths(1)->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
        ]);

        $monthKey = $month->format('Y-m');
        $this->assertSame(1, $result[$monthKey]['live_hit_count']);
    }

    public function test_handle_range_dedupes_and_scopes_the_same_as_handle(): void
    {
        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedHit(['ocr' => 'RNG0001', 'hit_type' => 'L', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'RNG0001', 'hit_type' => 'L', 'hit_created_at' => $month->copy()->addHours(1)->toDateTimeString()]);
        $this->seedHit(['ocr' => 'RNG0002', 'hit_type' => 'H', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit([
            'ocr' => 'RNG0003',
            'hit_type' => 'L',
            'hit_created_at' => $month->toDateTimeString(),
            'kit_org_id' => self::OTHER_ORG_ID,
            'hotlist_org_id' => self::ORG_ID,
        ]);

        $result = HitPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => $month->copy()->startOfMonth()->toDateTimeString(),
            'end' => $month->copy()->endOfMonth()->toDateTimeString(),
        ]);

        $this->assertSame([
            'live_hit_count' => 1,
            'historical_hit_count' => 1,
            'staging_live_hit_count' => 0,
            'staging_historical_hit_count' => 0,
        ], $result);
    }

    public function test_handle_range_zero_fills_when_no_rows_match(): void
    {
        $result = HitPlateStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
        ]);

        $this->assertSame([
            'live_hit_count' => 0,
            'historical_hit_count' => 0,
            'staging_live_hit_count' => 0,
            'staging_historical_hit_count' => 0,
        ], $result);
    }

    private function seedHit(array $overrides = []): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')->insert(array_merge([
            'id' => $this->hitSeq++,
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => 1,
            'kit_name' => 'Test Kit',
            'kit_org_id' => self::ORG_ID,
            'hotlist_org_id' => self::ORG_ID,
            'hotlist_us_state' => 'TX',
            'hotlist_plate' => 'ABC1234',
            'hotlist_status' => 1,
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_peerdb_hotlist_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ], $overrides));
    }
}
