<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\Queries\HitStatsBatchQuery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clickhouse')]
class HitStatsBatchQueryTest extends TestCase
{
    use RequiresClickhouse;

    private const ORG_ID = 642;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();
    }

    public function test_handle_dedupes_replacing_merge_tree_versions_per_month(): void
    {
        // hit_stats_daily is ReplacingMergeTree(_peerdb_synced_at) ORDER BY (org_id, date).
        // The hourly APPEND MV writes a fresh full-day recompute per (date, org_id)
        // every hour, so the same key carries multiple versioned rows until a merge
        // collapses them. Only the latest version is true; a naive sum() (the bug)
        // adds the stale recomputes on top and over-counts. FINAL must keep only the
        // latest version per key — then sum across distinct keys.
        $this->seedDailyRows();

        $result = HitStatsBatchQuery::handle([
            'org_id' => self::ORG_ID,
            'start' => '2026-06-01 00:00:00',
            'end' => '2026-06-30 23:59:59',
        ]);

        // live = 80 (Jun-20 latest, NOT the stale 100) + 5 (Jun-21) = 85.
        // The broken naive sum would be 100 + 80 + 5 = 185.
        $this->assertSame([
            'live_hit_count' => 85,
            'historical_hit_count' => 77,
            'staging_live_hit_count' => 69,
            'staging_historical_hit_count' => 61,
        ], $result['2026-06']);
    }

    public function test_handle_range_dedupes_replacing_merge_tree_versions(): void
    {
        $this->seedDailyRows();

        $result = HitStatsBatchQuery::handleRange([
            'org_id' => self::ORG_ID,
            'start' => '2026-06-01 00:00:00',
            'end' => '2026-06-30 23:59:59',
        ]);

        $this->assertSame([
            'live_hit_count' => 85,
            'historical_hit_count' => 77,
            'staging_live_hit_count' => 69,
            'staging_historical_hit_count' => 61,
        ], $result);
    }

    /**
     * Two rows for Jun-20 differing only by `_peerdb_synced_at` (the table's ReplacingMergeTree
     * version column in production; the later one supersedes) (the later one supersedes) plus a single Jun-21
     * row, so the assertions prove both: latest-version-wins dedup, and that summing
     * across distinct (date, org_id) keys still adds up.
     */
    private function seedDailyRows(): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hit_stats_daily')->insert([
            [
                'date' => '2026-06-20 00:00:00',
                'org_id' => self::ORG_ID,
                'live_hit_count' => 100,
                'historical_hit_count' => 200,
                'staging_live_hit_count' => 300,
                'staging_historical_hit_count' => 400,
                '_peerdb_synced_at' => '2026-06-20 10:00:00',
            ],
            [
                'date' => '2026-06-20 00:00:00',
                'org_id' => self::ORG_ID,
                'live_hit_count' => 80,
                'historical_hit_count' => 70,
                'staging_live_hit_count' => 60,
                'staging_historical_hit_count' => 50,
                '_peerdb_synced_at' => '2026-06-20 11:00:00',
            ],
            [
                'date' => '2026-06-21 00:00:00',
                'org_id' => self::ORG_ID,
                'live_hit_count' => 5,
                'historical_hit_count' => 7,
                'staging_live_hit_count' => 9,
                'staging_historical_hit_count' => 11,
                '_peerdb_synced_at' => '2026-06-21 09:00:00',
            ],
        ]);
    }
}
