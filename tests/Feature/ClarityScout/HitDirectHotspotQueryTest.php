<?php

declare(strict_types=1);

namespace Tests\Feature\ClarityScout;

use App\Clickhouse\Queries\HitDirectHotspotQuery;
use App\Enums\HotlistStatusEnum;
use App\Models\Org;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clarity-scout')]
class HitDirectHotspotQueryTest extends TestCase
{
    use RequiresClickhouse;

    private int $orgId;

    private int $hotlistSeq = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgId = Org::factory()->create()->id;
    }

    public function test_aggregates_open_direct_hits_into_a_geohash_cell(): void
    {
        $hotlistId = $this->seedHotlist();
        $this->seedHit($hotlistId, 32.7767, -96.7970);

        $rows = HitDirectHotspotQuery::handle(['org_id' => $this->orgId]);

        $this->assertSame(1, $this->totalCount($rows));
    }

    public function test_excludes_orders_from_other_orgs(): void
    {
        // Scoping is by the hit's `hotlist_org_id`; a staged order lives under the
        // forwarder org, so it falls outside this org's direct hotspots.
        $hotlistId = $this->seedHotlist();
        $this->seedHit($hotlistId, 32.7767, -96.7970, ['hotlist_org_id' => $this->orgId + 1]);

        $rows = HitDirectHotspotQuery::handle(['org_id' => $this->orgId]);

        $this->assertSame(0, $this->totalCount($rows));
    }

    public function test_excludes_non_open_orders(): void
    {
        // The order's CURRENT status (the hotlist row, FINAL) is authoritative —
        // NOT the hit's frozen hotlist_status (DEV-2117). Frozen Open, currently
        // Closed → excluded. This is the real leak the prior frozen-column test
        // could not catch.
        $hotlistId = $this->seedHotlist(['status' => HotlistStatusEnum::Closed->value]);
        $this->seedHit($hotlistId, 32.7767, -96.7970, [
            'hotlist_status' => HotlistStatusEnum::Open->value,
        ]);

        $rows = HitDirectHotspotQuery::handle(['org_id' => $this->orgId]);

        $this->assertSame(0, $this->totalCount($rows));
    }

    public function test_excludes_repossessed_hits(): void
    {
        // Currently Repossessed at the hotlist row, even though the hit is frozen Open.
        $hotlistId = $this->seedHotlist(['status' => HotlistStatusEnum::Repossessed->value]);
        $this->seedHit($hotlistId, 32.7767, -96.7970, [
            'hotlist_status' => HotlistStatusEnum::Open->value,
        ]);

        $rows = HitDirectHotspotQuery::handle(['org_id' => $this->orgId]);

        $this->assertSame(0, $this->totalCount($rows));
    }

    public function test_includes_orders_currently_open_despite_a_stale_frozen_status(): void
    {
        // Inverse of the leak: a hit frozen Closed whose order is CURRENTLY Open
        // must still shade the cell — proving the density follows the live hotlist,
        // not the frozen per-hit column (DEV-2117).
        $hotlistId = $this->seedHotlist(['status' => HotlistStatusEnum::Open->value]);
        $this->seedHit($hotlistId, 32.7767, -96.7970, [
            'hotlist_status' => HotlistStatusEnum::Closed->value,
        ]);

        $rows = HitDirectHotspotQuery::handle(['org_id' => $this->orgId]);

        $this->assertSame(1, $this->totalCount($rows));
    }

    public function test_resolves_latest_hotlist_version_on_status_transition(): void
    {
        // Two CDC versions of one hotlist (v1 Open, v2 Repossessed). The hit stays
        // frozen Open; FINAL over clarity.hotlist must resolve the latest version
        // (Repossessed) and drop the cell — pinning the load-bearing ApplyFinal
        // dependency (DEV-2117, mirrors the DEV-2113 marker fix).
        $id = $this->hotlistSeq++;

        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            ['id' => $id, 'org_id' => $this->orgId, 'status' => HotlistStatusEnum::Open->value, 'order_type' => 1, 'is_staged' => false, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(), '_peerdb_synced_at' => now()->toDateTimeString(), '_version' => 1, '_peerdb_is_deleted' => 0],
            ['id' => $id, 'org_id' => $this->orgId, 'status' => HotlistStatusEnum::Repossessed->value, 'order_type' => 1, 'is_staged' => false, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(), '_peerdb_synced_at' => now()->toDateTimeString(), '_version' => 2, '_peerdb_is_deleted' => 0],
        ]);

        $this->seedHit($id, 32.7767, -96.7970, [
            'hotlist_status' => HotlistStatusEnum::Open->value,
        ]);

        $rows = HitDirectHotspotQuery::handle(['org_id' => $this->orgId]);

        $this->assertSame(0, $this->totalCount($rows));
    }

    public function test_dedupes_repeat_hits_within_the_same_hour_and_location(): void
    {
        $hotlistId = $this->seedHotlist();

        // Two hits at the same spot in the same hour collapse to one; a third in
        // a different hour counts separately → 2.
        $hour = now()->subDays(1)->startOfHour();
        $this->seedHit($hotlistId, 32.7767, -96.7970, ['hit_created_at' => $hour->toDateTimeString()]);
        $this->seedHit($hotlistId, 32.7767, -96.7970, ['hit_created_at' => $hour->copy()->addMinutes(20)->toDateTimeString()]);
        $this->seedHit($hotlistId, 32.7767, -96.7970, ['hit_created_at' => $hour->copy()->subHours(2)->toDateTimeString()]);

        $rows = HitDirectHotspotQuery::handle(['org_id' => $this->orgId]);

        $this->assertSame(2, $this->totalCount($rows));
    }

    public function test_tier_color_follows_the_warning_ramp(): void
    {
        $this->assertSame('#22C55E', HitDirectHotspotQuery::getTierColor(1));
        $this->assertSame('#EAB308', HitDirectHotspotQuery::getTierColor(3));
        $this->assertSame('#EF4444', HitDirectHotspotQuery::getTierColor(5));

        $this->assertSame('hit_tier_1', HitDirectHotspotQuery::getTier(1));
        $this->assertSame('hit_tier_2_4', HitDirectHotspotQuery::getTier(3));
        $this->assertSame('hit_tier_5_plus', HitDirectHotspotQuery::getTier(5));

        $this->assertSame('1 hit', HitDirectHotspotQuery::getTierLabel(1));
        $this->assertSame('2-4 hits', HitDirectHotspotQuery::getTierLabel(3));
        $this->assertSame('5+ hits', HitDirectHotspotQuery::getTierLabel(5));
    }

    private function seedHotlist(array $overrides = []): int
    {
        $id = $this->hotlistSeq++;

        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert(array_merge([
            'id' => $id,
            'org_id' => $this->orgId,
            'status' => HotlistStatusEnum::Open->value,
            'order_type' => 1,
            'is_staged' => false,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ], $overrides));

        return $id;
    }

    private function seedHit(int $hotlistId, float $lat, float $lng, array $overrides = []): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')->insert(array_merge([
            'hotlist_id' => $hotlistId,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->subDays(1)->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->subDays(1)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => 1,
            'kit_name' => 'Test Kit',
            'kit_org_id' => $this->orgId,
            'hotlist_org_id' => $this->orgId,
            'hotlist_us_state' => 'TX',
            'hotlist_plate' => 'ABC1234',
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'lat' => $lat,
            'lng' => $lng,
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ], $overrides));
    }

    /**
     * @param  list<object>|list<array<string, mixed>>  $rows
     */
    private function totalCount(array $rows): int
    {
        return array_sum(array_map(
            fn ($row) => (int) ($row->hit_count ?? ($row['hit_count'] ?? 0)),
            $rows
        ));
    }
}
