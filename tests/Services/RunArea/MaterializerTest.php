<?php

declare(strict_types=1);

namespace Tests\Services\RunArea;

use App\Models\Org;
use App\Models\OrgRunArea;
use App\Services\RunArea\Materializer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

class MaterializerTest extends TestCase
{
    #[Group('run-area')]
    public function test_materializes_polygon_input_into_multipolygon_in_4326(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'polygon', 'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-97.0, 32.5], [-96.5, 32.5], [-96.5, 33.0], [-97.0, 33.0], [-97.0, 32.5],
                ]],
            ]],
        ]);

        Materializer::materialize($runArea);

        $row = $this->fetchGeomRow($runArea->id);

        $this->assertSame('ST_MultiPolygon', $row->type);
        $this->assertSame(4326, (int) $row->srid);
        $this->assertTrue((bool) $row->valid, 'materialized geometry must be valid');
        $this->assertSame('tiger:2019', $row->source_vintage);
        $this->assertTrue((bool) $row->stamped, 'materialized_at must be stamped');
    }

    #[Group('run-area')]
    public function test_materialized_polygon_contains_inside_point_and_excludes_outside_point(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'polygon', 'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-97.0, 32.5], [-96.5, 32.5], [-96.5, 33.0], [-97.0, 33.0], [-97.0, 32.5],
                ]],
            ]],
        ]);

        Materializer::materialize($runArea);

        $this->assertTrue($this->contains($runArea->id, -96.75, 32.75));
        $this->assertFalse($this->contains($runArea->id, -100.0, 40.0));
    }

    #[Group('run-area')]
    public function test_unions_multiple_polygon_entries_into_a_single_multipolygon(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'polygon', 'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[-97, 32], [-96, 32], [-96, 33], [-97, 33], [-97, 32]]],
            ]],
            ['kind' => 'polygon', 'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[-90, 40], [-89, 40], [-89, 41], [-90, 41], [-90, 40]]],
            ]],
        ]);

        Materializer::materialize($runArea);

        $parts = (int) DB::connection('platform')->selectOne(
            'SELECT public.ST_NumGeometries(geometry) AS n FROM org_run_areas WHERE id = ?',
            [$runArea->id],
        )->n;

        $this->assertSame(2, $parts, 'two disjoint polygons should produce a MultiPolygon with 2 parts');
    }

    #[Group('run-area')]
    public function test_empty_inputs_leaves_geometry_null_and_does_not_stamp_metadata(): void
    {
        $runArea = $this->makeRunArea([]);

        Materializer::materialize($runArea);

        $row = $this->fetchGeomRow($runArea->id);

        $this->assertNull($row->type, 'geometry should remain NULL for empty inputs');
        $this->assertNull($row->source_vintage);
        $this->assertFalse((bool) $row->stamped);
    }

    #[Group('run-area')]
    public function test_entries_with_unknown_kind_are_skipped_defensively(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'nonsense', 'reference' => 'anything'],
        ]);

        Materializer::materialize($runArea);

        $row = $this->fetchGeomRow($runArea->id);

        $this->assertNull($row->type, 'no recognized entries should leave geometry NULL');
    }

    #[Group('run-area')]
    public function test_recognized_input_resolving_to_nothing_throws_and_does_not_stamp_metadata(): void
    {
        // A state reference matching no TIGER row still builds a piece, so the UPDATE
        // runs and writes a NULL geometry. Before DEV-2252 that was stamped as
        // materialized, leaving a run area that claimed a boundary it did not have.
        $runArea = $this->makeRunArea([
            ['kind' => 'state', 'reference' => 'ZZ'],
        ]);

        // Assert on the message, not just the type: QueryException extends
        // RuntimeException, so a bare type expectation would also pass if the UPDATE
        // failed before the guard ever ran.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to mark it materialized');

        try {
            Materializer::materialize($runArea);
        } finally {
            $row = $this->fetchGeomRow($runArea->id);

            $this->assertNull($row->type, 'geometry must stay NULL when inputs resolve to nothing');
            $this->assertNull($row->source_vintage);
            $this->assertFalse((bool) $row->stamped, 'materialized_at must not be stamped');
        }
    }

    /**
     * ST_Union over ARRAY[...] drops NULL members, so a polygon alongside an
     * unresolvable state reference still produces a non-empty geometry. Checking
     * emptiness alone would stamp the row as materialized while silently excluding
     * the entire state — which then under-clips every Scout heatmap with no signal.
     */
    #[Group('run-area')]
    public function test_unresolvable_reference_is_rejected_even_when_a_polygon_supplies_geometry(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'polygon', 'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-97.0, 32.5], [-96.5, 32.5], [-96.5, 33.0], [-97.0, 33.0], [-97.0, 32.5],
                ]],
            ]],
            ['kind' => 'state', 'reference' => 'ZZ'],
        ]);

        $caught = null;

        try {
            Materializer::materialize($runArea);
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }

        $this->assertNotNull($caught, 'a partially-unresolvable input set must throw');
        $this->assertStringContainsString('state:ZZ', $caught);

        $row = $this->fetchGeomRow($runArea->id);

        $this->assertNull($row->type, 'a partial materialize must roll back, not persist');
        $this->assertFalse((bool) $row->stamped, 'materialized_at must not be stamped');
    }

    /**
     * A recognized kind that yields no SQL piece — here `geometry` supplied as a JSON
     * string rather than an array — contributes nothing to the union. Reaching the same
     * silent-drop through that door must be rejected too, not just an unmatched
     * state/MSA reference. `inputs` is Backyard-owned and unvalidated in Clarity.
     */
    #[Group('run-area')]
    public function test_recognized_input_that_yields_no_geometry_piece_is_rejected(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'polygon', 'geometry' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[-97.0, 32.5], [-96.5, 32.5], [-96.5, 33.0], [-97.0, 33.0], [-97.0, 32.5]]],
            ])],
            ['kind' => 'polygon', 'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[-90.0, 40.0], [-89.0, 40.0], [-89.0, 41.0], [-90.0, 41.0], [-90.0, 40.0]]],
            ]],
        ]);

        $caught = null;

        try {
            Materializer::materialize($runArea);
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }

        $this->assertNotNull($caught, 'a dropped polygon entry must not pass silently');
        $this->assertStringContainsString('polygon at index 0', $caught);

        $row = $this->fetchGeomRow($runArea->id);

        $this->assertNull($row->type, 'a partial materialize must roll back');
        $this->assertFalse((bool) $row->stamped);
    }

    /**
     * The all-malformed set is the likelier shape of the same mistake: one bad
     * serialization drops every entry. Returning silently would leave a previously
     * materialized row holding its old geometry and old materialized_at while the
     * operator's boundary change is ignored with no signal at all.
     */
    #[Group('run-area')]
    public function test_inputs_that_all_yield_no_geometry_piece_are_rejected_rather_than_no_oped(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'polygon', 'geometry' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[-97.0, 32.5], [-96.5, 32.5], [-96.5, 33.0], [-97.0, 33.0], [-97.0, 32.5]]],
            ])],
            ['kind' => 'state', 'reference' => 48],
        ]);

        $caught = null;

        try {
            Materializer::materialize($runArea);
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }

        $this->assertNotNull($caught, 'an all-malformed input set must not no-op silently');
        $this->assertStringContainsString('polygon at index 0', $caught);
        $this->assertStringContainsString('state at index 1', $caught);

        $row = $this->fetchGeomRow($runArea->id);

        $this->assertNull($row->type);
        $this->assertFalse((bool) $row->stamped);
    }

    #[Group('run-area')]
    public function test_failed_rematerialize_preserves_the_previously_materialized_geometry(): void
    {
        $runArea = $this->makeRunArea([
            ['kind' => 'polygon', 'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-97.0, 32.5], [-96.5, 32.5], [-96.5, 33.0], [-97.0, 33.0], [-97.0, 32.5],
                ]],
            ]],
        ]);

        Materializer::materialize($runArea);
        $this->assertSame('ST_MultiPolygon', $this->fetchGeomRow($runArea->id)->type);

        // Re-materializing against an unresolvable input must not wipe the good boundary.
        $runArea->update(['inputs' => [['kind' => 'state', 'reference' => 'ZZ']]]);

        // PHPUnit's own AssertionFailedError extends RuntimeException, so a fail()
        // inside this try would be swallowed by the catch. Assert on the caught
        // message instead, outside any branch that could eat it.
        $caught = null;

        try {
            Materializer::materialize($runArea->fresh());
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }

        $this->assertNotNull($caught, 'an empty-geometry re-materialize must throw');
        $this->assertStringContainsString('refusing to mark it materialized', $caught);

        $row = $this->fetchGeomRow($runArea->id);

        $this->assertSame('ST_MultiPolygon', $row->type, 'the prior geometry must survive a failed re-materialize');
        $this->assertTrue((bool) $row->stamped);
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     */
    private function makeRunArea(array $inputs): OrgRunArea
    {
        $org = Org::factory()->create();

        return OrgRunArea::create([
            'org_id' => $org->id,
            'inputs' => $inputs,
        ]);
    }

    private function fetchGeomRow(int $id): object
    {
        return DB::connection('platform')->selectOne(
            'SELECT
                public.ST_GeometryType(geometry) AS type,
                public.ST_SRID(geometry) AS srid,
                public.ST_IsValid(geometry) AS valid,
                source_vintage,
                materialized_at IS NOT NULL AS stamped
             FROM org_run_areas WHERE id = ?',
            [$id],
        );
    }

    private function contains(int $id, float $lng, float $lat): bool
    {
        $r = DB::connection('platform')->selectOne(
            'SELECT public.ST_Contains(geometry, public.ST_SetSRID(public.ST_MakePoint(?, ?), 4326)) AS hit
             FROM org_run_areas WHERE id = ?',
            [$lng, $lat, $id],
        );

        return (bool) ($r->hit ?? false);
    }
}
