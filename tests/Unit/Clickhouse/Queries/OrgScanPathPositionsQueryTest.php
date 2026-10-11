<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\OrgScanPathPositionsQuery;
use App\Models\Kit;
use App\Models\Org;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class OrgScanPathPositionsQueryTest extends TestCase
{
    use RequiresClickhouse;

    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Org::factory()->create();
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_returns_the_last_point_of_the_newest_bucket_per_kit(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($kit->id, '[(-74.5, 39.5), (-74.6, 39.6)]', now()->subMinutes(30));
        $this->insertKitScanPath($kit->id, '[(-80.0, 45.0), (-81.0, 46.0)]', now());

        $rows = OrgScanPathPositionsQuery::query([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($kit->id, $rows[0]['kit_id']);
        $this->assertSame(-81.0, $rows[0]['lng']);
        $this->assertSame(46.0, $rows[0]['lat']);
    }

    /**
     * The one thing GROUP BY kit_id exists for: two kits in the same batched
     * call must each get THEIR OWN point, not each other's. A shared/default
     * fixture point across kits would let this pass even if GROUP BY kit_id
     * were dropped or the result were keyed wrongly downstream.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_two_kits_in_the_same_call_get_their_own_distinct_point(): void
    {
        $kitA = Kit::factory()->forOrg($this->org)->create();
        $kitB = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($kitA->id, '[(-74.5, 39.5)]', now());
        $this->insertKitScanPath($kitB->id, '[(-118.25, 34.05)]', now());

        $rows = OrgScanPathPositionsQuery::query([
            'org_id' => $this->org->id,
            'kit_ids' => [$kitA->id, $kitB->id],
        ]);

        $byId = collect($rows)->keyBy('kit_id');

        $this->assertCount(2, $rows);
        $this->assertSame(-74.5, $byId[$kitA->id]['lng']);
        $this->assertSame(39.5, $byId[$kitA->id]['lat']);
        $this->assertSame(-118.25, $byId[$kitB->id]['lng']);
        $this->assertSame(34.05, $byId[$kitB->id]['lat']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_final_collapses_a_duplicate_version_row_to_the_latest(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();
        $timestamp = now();

        $this->insertKitScanPath($kit->id, '[(-74.5, 39.5)]', $timestamp, $timestamp->copy()->subSecond());
        $this->insertKitScanPath($kit->id, '[(-80.0, 45.0)]', $timestamp, $timestamp->copy());

        // Positive control: both fixture rows share the FULL sort key
        // (org_id, kit_id, timestamp), so a background merge could collapse
        // them before FINAL ever gets a say. Without this, the test could
        // pass because ClickHouse already deduped them at write time, not
        // because FINAL deduped them at read time.
        $rawCount = DB::connection('clarity_clickhouse')->table('clarity.kit_scan_paths')
            ->where('org_id', $this->org->id)
            ->where('kit_id', $kit->id)
            ->where('timestamp', $timestamp->toDateTimeString())
            ->count();
        $this->assertSame(2, $rawCount, 'Fixture setup assumption broken: both duplicate-key rows must still be on disk pre-FINAL.');

        $rows = OrgScanPathPositionsQuery::query([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(-80.0, $rows[0]['lng']);
        $this->assertSame(45.0, $rows[0]['lat']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_org_scoping_excludes_another_orgs_bucket(): void
    {
        $otherOrg = Org::factory()->create();
        $kit = Kit::factory()->forOrg($this->org)->create();

        // Same kit_id, but attributed to a different org — must not leak.
        $this->insertKitScanPathForOrg($otherOrg->id, $kit->id, '[(-80.0, 45.0)]', now());

        // handle() directly, not query(): query() swallows every Throwable to
        // [] under APP_ENV=testing, which would make this assertion pass even
        // if the read threw for an unrelated reason.
        $rows = OrgScanPathPositionsQuery::handle([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
        ]);

        $this->assertSame([], $rows);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_a_bucket_older_than_the_cutoff_is_excluded(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($kit->id, '[(-74.5, 39.5)]', now()->subHours(30));

        // handle() directly — see test_org_scoping_excludes_another_orgs_bucket.
        $rows = OrgScanPathPositionsQuery::handle([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
            'cutoff' => now()->subHours(24)->toDateTimeString(),
        ]);

        $this->assertSame([], $rows);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_empty_kit_ids_short_circuits(): void
    {
        // handle(), not query(): query() swallows any Throwable to [] under
        // APP_ENV=testing, which would make this assertion unfalsifiable.
        $rows = OrgScanPathPositionsQuery::handle([
            'org_id' => $this->org->id,
            'kit_ids' => [],
        ]);

        $this->assertSame([], $rows);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_a_bucket_with_no_points_is_omitted(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($kit->id, '[]', now());

        // handle() directly, not query(): query() swallows any Throwable to
        // [] under APP_ENV=testing, which could mask an unrelated regression
        // as a passing test. (Verified: an empty path_points array here
        // renders as a real (0,0) row rather than throwing — see the guard's
        // comment in OrgScanPathPositionsQuery — so this assertion is
        // falsifiable on its own terms regardless.)
        $rows = OrgScanPathPositionsQuery::handle([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
        ]);

        $this->assertSame([], $rows);
    }

    /**
     * kit_scan_paths.path_points derives from the scan tables (`lat`/`lng`
     * Float64 DEFAULT 0), a different producer than sc_positions — a bucket
     * whose last point is (0,0) must not surface as a phantom dot.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_a_bucket_whose_last_point_is_zero_geo_is_excluded(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($kit->id, '[(-74.5, 39.5), (0, 0)]', now());

        $rows = OrgScanPathPositionsQuery::handle([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
        ]);

        $this->assertSame([], $rows);
    }

    private function insertKitScanPath(int $kitId, string $pathPoints, Carbon $timestamp, ?Carbon $version = null): void
    {
        $this->insertKitScanPathForOrg($this->org->id, $kitId, $pathPoints, $timestamp, $version);
    }

    private function insertKitScanPathForOrg(int $orgId, int $kitId, string $pathPoints, Carbon $timestamp, ?Carbon $version = null): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.kit_scan_paths')->insert([
            'kit_id' => $kitId,
            'org_id' => $orgId,
            'timestamp' => $timestamp->toDateTimeString(),
            'scan_count' => 1,
            'path_points' => $pathPoints,
            'kit_name' => 'Test Kit '.$kitId,
            '_version' => ($version ?? $timestamp)->format('Y-m-d H:i:s.u'),
        ]);
    }
}
