<?php

declare(strict_types=1);

namespace Tests\Feature\RdnMetrics\Clickhouse;

use App\Clickhouse\Queries\RdnHitHourCountQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('rdn-metrics')]
#[Group('clickhouse')]
class RdnHitHourCountQueryTest extends TestCase
{
    use RequiresClickhouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();
    }

    public function test_counts_each_hit_type_within_the_hour_window(): void
    {
        $orgId = 100;
        $hourStart = $this->hourWindowStart();
        $hourEnd = $hourStart->addHour();
        $hitTimestamp = $hourStart->addMinutes(30)->toDateTimeString();

        $this->seedHits($orgId, 'L', 47, $hitTimestamp);
        $this->seedHits($orgId, 'H', 12, $hitTimestamp);
        $this->seedHits($orgId, 'S', 3, $hitTimestamp);
        $this->seedHits($orgId, 'Z', 1, $hitTimestamp);

        // Hits that should NOT be counted:
        $this->seedHits($orgId, 'M', 5, $hitTimestamp);
        $this->seedHits($orgId, 'F', 4, $hitTimestamp);
        $this->seedHits($orgId, 'P', 3, $hitTimestamp);

        // Hit outside the window (1 second before start) — should NOT count
        $this->seedHits($orgId, 'L', 9, $hourStart->subSecond()->toDateTimeString());
        // Hit in a different org — should NOT count
        $this->seedHits(orgId: 999, hitType: 'L', count: 8, hitCreatedAt: $hitTimestamp);

        $counts = RdnHitHourCountQuery::handle([
            'org_id' => $orgId,
            'range_start' => $hourStart->format('Y-m-d H:i:s'),
            'range_end' => $hourEnd->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame([
            'lives' => 47,
            'hh' => 12,
            'staging_lives' => 3,
            'aa' => 1,
        ], $counts);
    }

    public function test_hit_at_range_end_is_excluded(): void
    {
        // The query uses hit_created_at < range_end (right-exclusive).
        // A hit stamped exactly at the bound belongs to the NEXT hour's window
        // and must NOT be counted in this hour's tally. If this test regresses,
        // someone has flipped `<` to `<=` and we're double-counting boundary hits.

        $orgId = 200;
        $hourStart = $this->hourWindowStart();
        $hourEnd = $hourStart->addHour();

        // 1 hit just inside the window — should count
        $this->seedHits($orgId, 'L', 1, $hourEnd->subSecond()->toDateTimeString());

        // 1 hit exactly at range_end — must NOT count
        $this->seedHits($orgId, 'L', 1, $hourEnd->toDateTimeString());

        $counts = RdnHitHourCountQuery::handle([
            'org_id' => $orgId,
            'range_start' => $hourStart->format('Y-m-d H:i:s'),
            'range_end' => $hourEnd->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(1, $counts['lives']);
    }

    public function test_returns_zero_counts_when_no_hits_exist(): void
    {
        $counts = RdnHitHourCountQuery::handle([
            'org_id' => 12345,
            'range_start' => '2026-05-01 00:00:00',
            'range_end' => '2026-05-01 01:00:00',
        ]);

        $this->assertSame(['lives' => 0, 'hh' => 0, 'staging_lives' => 0, 'aa' => 0], $counts);
    }

    public function test_throws_on_malformed_range_start(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RdnHitHourCountQuery::handle([
            'org_id' => 1,
            'range_start' => "2026-05-01 00:00:00'; DROP TABLE x; --",
            'range_end' => '2026-05-01 01:00:00',
        ]);
    }

    public function test_throws_on_malformed_range_end(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RdnHitHourCountQuery::handle([
            'org_id' => 1,
            'range_start' => '2026-05-01 00:00:00',
            'range_end' => '2026-05-01T01:00:00Z', // ISO format isn't Y-m-d H:i:s
        ]);
    }

    /**
     * Whole-hour window anchor, relative to now. `clarity.hits_hot` carries a
     * 90-day DELETE TTL on hit_created_at, so ClickHouse drops fixtures older
     * than that the moment they're inserted and every count reads back zero.
     * Never anchor these fixtures to a hardcoded date — it silently ages out.
     */
    protected function hourWindowStart(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfHour()->subHour();
    }

    protected function seedHits(int $orgId, string $hitType, int $count, string $hitCreatedAt): void
    {
        static $idCounter = 1;

        for ($i = 0; $i < $count; $i++) {
            DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
                'id' => $idCounter++,
                'hotlist_id' => 1,
                'scan_id' => 1,
                'hit_type' => $hitType,
                'hit_created_at' => $hitCreatedAt,
                'ocr' => 'ABC1234',
                'scanned_at' => $hitCreatedAt,
                'camera_name' => 'cam1',
                'scan_us_state' => 'TX',
                'kit_id' => 1,
                'kit_name' => 'Test Kit',
                'kit_org_id' => $orgId,
                'hotlist_org_id' => $orgId,
                '_version' => 1,
                '_peerdb_is_deleted' => 0,
            ]);
        }
    }
}
