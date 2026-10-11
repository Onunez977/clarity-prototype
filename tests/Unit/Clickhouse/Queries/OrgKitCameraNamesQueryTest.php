<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\OrgKitCameraNamesQuery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class OrgKitCameraNamesQueryTest extends TestCase
{
    use RequiresClickhouse;

    #[Group('clickhouse')]
    public function test_empty_kit_ids_returns_an_empty_array(): void
    {
        $this->assertSame([], OrgKitCameraNamesQuery::handle(['kit_ids' => []]));
    }

    #[Group('clickhouse')]
    public function test_missing_kit_ids_returns_an_empty_array(): void
    {
        $this->assertSame([], OrgKitCameraNamesQuery::handle([]));
    }

    /**
     * Pins the PHP-side de-dup, which is what actually keeps the returned lists
     * unique. Deliberately NOT a FINAL test: both columns are in the sort key, so
     * every ReplacingMergeTree duplicate is byte-identical and the `in_array` guard
     * collapses it with or without FINAL — see the class docblock.
     */
    #[Group('clickhouse')]
    public function test_duplicate_pairs_collapse_to_one_kit_id(): void
    {
        // Three inserts in three statements, so the duplicate pair lands in its own part and
        // dedup is genuinely exercised whether or not ClickHouse has merged parts yet.
        $this->insertPair(4101, 'GL4101');
        $this->insertPair(4101, 'GL4101');
        $this->insertPair(4102, 'GL4101');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4101, 4102]]);

        $this->assertSame(['GL4101' => [4101, 4102]], $result);
    }

    /**
     * The MV applies no lower() and the scanner feed is not case-normalized, so without
     * case-folding one camera renders as two rows with independently computed rollups —
     * the ticket's own "one row per GL" invariant, broken by a variant of its root cause.
     */
    #[Group('clickhouse')]
    public function test_case_variant_names_merge_onto_one_entry(): void
    {
        $this->insertPair(4601, 'GLcase');
        $this->insertPair(4602, 'GLCASE');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4601, 4602]]);

        // Pins the SPELLING, not just the merge: the GL string is the rendered identifier and the
        // Filament record key, so lowercasing it is a user-visible regression. Asserting only
        // assertCount(1) would let `$key = strtolower($cameraName)` ship green.
        $this->assertSame(['GLcase' => [4601, 4602]], $result);
    }

    /**
     * Kit-to-GL is many-to-many in production: one kit user drives several cameras.
     */
    #[Group('clickhouse')]
    public function test_one_kit_with_several_camera_names_yields_one_entry_each(): void
    {
        $this->insertPair(4201, 'GL4201');
        $this->insertPair(4201, 'GL4202');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4201]]);

        $this->assertSame(['GL4201' => [4201], 'GL4202' => [4201]], $result);
    }

    /**
     * The other direction, and the reason this class exists: several kits share one
     * GL, so the resource can roll their last-scan and reporting states up to it.
     */
    #[Group('clickhouse')]
    public function test_several_kits_sharing_one_camera_name_group_under_it(): void
    {
        $this->insertPair(4301, 'GL4300');
        $this->insertPair(4302, 'GL4300');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4301, 4302]]);

        // assertSame, not assertEqualsCanonicalizing: the ORDER BY is documented as
        // load-bearing, so the order has to be pinned or deleting it stays green.
        $this->assertSame(['GL4300' => [4301, 4302]], $result);
    }

    /**
     * The table has no org_id, so tenancy is the caller's kit_ids list and nothing
     * else. A kit outside that list must not leak in even when it shares a GL with
     * one that is inside it.
     */
    #[Group('clickhouse')]
    public function test_a_kit_outside_the_requested_list_never_appears(): void
    {
        $this->insertPair(4401, 'GL4400');
        $this->insertPair(4402, 'GL4400');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4401]]);

        $this->assertSame(['GL4400' => [4401]], $result);
    }

    /**
     * The MV stores only the first token of camera_name, so a GL token arrives
     * already trimmed and must not be re-stripped.
     */
    #[Group('clickhouse')]
    public function test_camera_name_is_returned_verbatim(): void
    {
        $this->insertPair(4501, 'Relentless');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4501]]);

        $this->assertArrayHasKey('Relentless', $result);
    }

    /**
     * The case-fold merge puts two genuinely DISTINCT rows on one key, so the same kit can be
     * appended twice. `FINAL` cannot prevent it — the rows are not duplicates — which is why the
     * PHP guard exists and is not redundant with it.
     */
    #[Group('clickhouse')]
    public function test_one_kit_under_two_case_variants_appears_once(): void
    {
        $this->insertPair(4701, 'GLdual');
        $this->insertPair(4701, 'GLDUAL');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4701]]);

        $this->assertSame(['GLDUAL' => [4701]], $result);
    }

    /** A blank camera_name is not a camera and must not become a row. */
    #[Group('clickhouse')]
    public function test_a_blank_camera_name_is_skipped(): void
    {
        $this->insertPair(4801, '');
        $this->insertPair(4801, 'GL4801');

        $result = OrgKitCameraNamesQuery::handle(['kit_ids' => [4801]]);

        $this->assertSame(['GL4801' => [4801]], $result);
    }

    private function insertPair(int $kitId, string $cameraName): void
    {
        DB::connection('public_clickhouse')->table('aggregate_kit_camera_name')->insert([
            'kit_id' => $kitId,
            'camera_name' => $cameraName,
        ]);
    }
}
