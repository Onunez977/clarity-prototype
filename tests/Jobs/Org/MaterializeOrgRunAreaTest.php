<?php

declare(strict_types=1);

namespace Tests\Jobs\Org;

use App\Jobs\Org\MaterializeOrgRunArea;
use App\Models\Org;
use App\Models\OrgRunArea;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class MaterializeOrgRunAreaTest extends TestCase
{
    #[Group('run-area')]
    public function test_handle_returns_early_when_org_has_no_run_area_row(): void
    {
        $org = Org::factory()->create();

        (new MaterializeOrgRunArea($org))->handle();

        $this->assertDatabaseMissing('org_run_areas', ['org_id' => $org->id], 'platform');
    }

    #[Group('run-area')]
    public function test_handle_returns_early_when_inputs_is_empty(): void
    {
        $org = Org::factory()->create();
        OrgRunArea::create(['org_id' => $org->id, 'inputs' => []]);

        (new MaterializeOrgRunArea($org))->handle();

        $row = DB::connection('platform')
            ->table('org_run_areas')
            ->where('org_id', $org->id)
            ->first();

        $this->assertNull($row->geometry);
        $this->assertNull($row->materialized_at);
    }

    #[Group('run-area')]
    public function test_handle_materializes_geometry_when_inputs_are_present(): void
    {
        $org = Org::factory()->create();
        OrgRunArea::create([
            'org_id' => $org->id,
            'inputs' => [
                ['kind' => 'polygon', 'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [-97.0, 32.5], [-96.5, 32.5], [-96.5, 33.0], [-97.0, 33.0], [-97.0, 32.5],
                    ]],
                ]],
            ],
        ]);

        (new MaterializeOrgRunArea($org))->handle();

        $type = DB::connection('platform')->selectOne(
            'SELECT public.ST_GeometryType(geometry) AS t FROM org_run_areas WHERE org_id = ?',
            [$org->id],
        )->t;

        $this->assertSame('ST_MultiPolygon', $type);
    }
}
