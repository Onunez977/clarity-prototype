<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clickhouse')]
class StagingLiveHitLeaderQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const ORG_ID = 742;

    private const OTHER_ORG_ID = 743;

    private int $hitSeq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_handle_range_org_total_is_not_the_sum_of_kit_rows(): void
    {
        $this->setupRequiresClickhouse();

        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedHit(['ocr' => 'SHARE01', 'kit_id' => 11, 'kit_name' => 'Kit Eleven', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'SHARE01', 'kit_id' => 12, 'kit_name' => 'Kit Twelve', 'hit_created_at' => $month->copy()->addHours(1)->toDateTimeString()]);
        $this->seedHit(['ocr' => 'SHARE02', 'kit_id' => 12, 'kit_name' => 'Kit Twelve', 'hit_created_at' => $month->toDateTimeString()]);

        $result = StagingLiveHitLeaderQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => $month->copy()->startOfMonth()->toDateTimeString(),
            'end' => $month->copy()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertSame(2, $result['totals'][0]);
        $this->assertSame(1, $result['totals'][11]);
        $this->assertSame(2, $result['totals'][12]);
        $this->assertArrayNotHasKey(0, $result['names']);
    }

    public function test_handle_range_drops_a_real_kit_zero_detail_row(): void
    {
        $this->setupRequiresClickhouse();

        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedHit(['ocr' => 'ZERO001', 'kit_id' => 0, 'kit_name' => 'Kit Zero', 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'ZERO002', 'kit_id' => 7, 'kit_name' => 'Kit Seven', 'hit_created_at' => $month->toDateTimeString()]);

        $result = StagingLiveHitLeaderQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => $month->copy()->startOfMonth()->toDateTimeString(),
            'end' => $month->copy()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertSame(2, $result['totals'][0]);
        $this->assertSame(1, $result['totals'][7]);
        $this->assertArrayNotHasKey(0, $result['names']);
        $this->assertCount(2, $result['totals']);
    }

    public function test_handle_range_only_counts_staging_live_hit_type(): void
    {
        $this->setupRequiresClickhouse();

        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedHit(['ocr' => 'TYP0001', 'hit_type' => 'S', 'kit_id' => 1, 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'TYP0002', 'hit_type' => 'L', 'kit_id' => 1, 'hit_created_at' => $month->toDateTimeString()]);
        $this->seedHit(['ocr' => 'TYP0003', 'hit_type' => 'H', 'kit_id' => 1, 'hit_created_at' => $month->toDateTimeString()]);

        $result = StagingLiveHitLeaderQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertSame(1, $result['totals'][0]);
        $this->assertSame(1, $result['totals'][1]);
    }

    public function test_handle_range_scopes_by_kit_org_id(): void
    {
        $this->setupRequiresClickhouse();

        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedHit([
            'ocr' => 'ORG00001',
            'kit_id' => 1,
            'hit_created_at' => $month->toDateTimeString(),
            'kit_org_id' => self::ORG_ID,
            'hotlist_org_id' => self::OTHER_ORG_ID,
        ]);
        $this->seedHit([
            'ocr' => 'ORG00002',
            'kit_id' => 2,
            'hit_created_at' => $month->toDateTimeString(),
            'kit_org_id' => self::OTHER_ORG_ID,
            'hotlist_org_id' => self::ORG_ID,
        ]);

        $result = StagingLiveHitLeaderQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertArrayHasKey(1, $result['totals']);
        $this->assertArrayNotHasKey(2, $result['totals']);
    }

    public function test_handle_range_excludes_soft_deleted_rows(): void
    {
        $this->setupRequiresClickhouse();

        $month = now()->startOfMonth()->addDays(5)->setTime(10, 0, 0);

        $this->seedHit([
            'ocr' => 'DEL0001',
            'kit_id' => 1,
            'hit_created_at' => $month->toDateTimeString(),
            '_peerdb_is_deleted' => 1,
        ]);

        $result = StagingLiveHitLeaderQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertSame(['totals' => [0 => 0], 'names' => []], $result);
    }

    public function test_handle_range_zero_fills_when_no_rows_match(): void
    {
        $this->setupRequiresClickhouse();

        $result = StagingLiveHitLeaderQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => now()->startOfMonth()->toDateTimeString(),
            'end' => now()->endOfMonth()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertSame(['totals' => [0 => 0], 'names' => []], $result);
    }

    private function seedHit(array $overrides = []): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')->insert(array_merge([
            'id' => $this->hitSeq++,
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'S',
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
