<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Org;
use App\Models\OrgRunArea;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class OrgRunAreaTest extends TestCase
{
    #[Group('run-area')]
    public function test_inputs_cast_to_array_and_materialized_at_to_carbon(): void
    {
        $org = Org::factory()->create();

        $runArea = OrgRunArea::create([
            'org_id' => $org->id,
            'inputs' => [
                ['kind' => 'state', 'reference' => 'TX'],
                ['kind' => 'msa', 'reference' => '19100'],
            ],
        ]);

        DB::connection('platform')
            ->table('org_run_areas')
            ->where('id', $runArea->id)
            ->update(['materialized_at' => '2026-05-28 12:00:00+00']);

        $fresh = $runArea->fresh();

        $this->assertIsArray($fresh->inputs);
        $this->assertSame('state', $fresh->inputs[0]['kind']);
        $this->assertSame('TX', $fresh->inputs[0]['reference']);
        $this->assertInstanceOf(Carbon::class, $fresh->materialized_at);
    }

    #[Group('run-area')]
    public function test_org_has_one_run_area_relation(): void
    {
        $org = Org::factory()->create();

        OrgRunArea::create([
            'org_id' => $org->id,
            'inputs' => [],
        ]);

        $this->assertNotNull($org->fresh()->runArea);
        $this->assertSame($org->id, $org->fresh()->runArea->org_id);
    }

    #[Group('run-area')]
    public function test_org_id_unique_constraint_enforces_one_run_area_per_org(): void
    {
        $org = Org::factory()->create();
        OrgRunArea::create(['org_id' => $org->id, 'inputs' => []]);

        $this->expectException(QueryException::class);
        OrgRunArea::create(['org_id' => $org->id, 'inputs' => []]);
    }

    #[Group('run-area')]
    public function test_with_geo_json_scope_projects_geometry_as_geojson_string(): void
    {
        $org = Org::factory()->create();
        $runArea = OrgRunArea::create(['org_id' => $org->id, 'inputs' => []]);

        DB::connection('platform')->statement(
            'UPDATE org_run_areas
                SET geometry = public.ST_Multi(public.ST_GeomFromText(?, 4326))
              WHERE id = ?',
            ['POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))', $runArea->id],
        );

        $loaded = OrgRunArea::query()->withGeoJson()->find($runArea->id);

        $this->assertNotNull($loaded->geometry_geojson);
        $decoded = json_decode($loaded->geometry_geojson, true);
        $this->assertSame('MultiPolygon', $decoded['type']);
    }

    #[Group('run-area')]
    public function test_raw_geometry_column_is_hidden_from_array_serialization(): void
    {
        $org = Org::factory()->create();
        $runArea = OrgRunArea::query()->withGeoJson()->find(
            OrgRunArea::create(['org_id' => $org->id, 'inputs' => []])->id,
        );

        $this->assertArrayNotHasKey('geometry', $runArea->toArray());
    }
}
