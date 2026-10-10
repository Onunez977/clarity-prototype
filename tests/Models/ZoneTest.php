<?php

namespace Tests\Models;

use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ZoneTest extends TestCase
{
    #[Group('zone')]
    public function test_rebuild_geometry_from_polygon_only_definition(): void
    {
        $zone = $this->makeZone([
            'polygons' => [[
                'type' => 'Polygon',
                'coordinates' => [[
                    [0, 0], [0, 1], [1, 1], [1, 0], [0, 0],
                ]],
            ]],
            'zip_codes' => [],
        ]);

        $zone->rebuildGeometryFromDefinition();

        $this->assertGeomPopulated($zone->id);
        $this->assertSame('ST_MultiPolygon', $this->geomType($zone->id));
    }

    #[Group('zone')]
    public function test_rebuild_geometry_from_zip_only_definition(): void
    {
        $this->seedTigerZip('75201', 'POLYGON((-96.81 32.78, -96.80 32.78, -96.80 32.79, -96.81 32.79, -96.81 32.78))');

        $zone = $this->makeZone([
            'polygons' => [],
            'zip_codes' => ['75201'],
        ]);

        $zone->rebuildGeometryFromDefinition();

        $this->assertGeomPopulated($zone->id);
        $this->assertSame('ST_MultiPolygon', $this->geomType($zone->id));

        // Verify the geom is in the right place — a point inside the seeded zip should intersect.
        // PostGIS lives in `public`; platform connection's search_path is `platform` only.
        $intersects = DB::connection('platform')->selectOne(
            'SELECT public.ST_Intersects(geom, public.ST_SetSRID(public.ST_MakePoint(-96.805, 32.785), 4326)) AS hit
             FROM zones WHERE id = ?',
            [$zone->id]
        );

        $this->assertTrue((bool) $intersects->hit, 'rebuilt zip geometry should contain the test point');
    }

    #[Group('zone')]
    public function test_rebuild_geometry_unions_polygons_and_zips(): void
    {
        $this->seedTigerZip('99999', 'POLYGON((10 10, 11 10, 11 11, 10 11, 10 10))');

        $zone = $this->makeZone([
            'polygons' => [[
                'type' => 'Polygon',
                'coordinates' => [[
                    [0, 0], [0, 1], [1, 1], [1, 0], [0, 0],
                ]],
            ]],
            'zip_codes' => ['99999'],
        ]);

        $zone->rebuildGeometryFromDefinition();

        $this->assertGeomPopulated($zone->id);

        // The resulting geom should be a MultiPolygon containing both pieces.
        $info = DB::connection('platform')->selectOne(
            'SELECT public.GeometryType(geom) AS gtype, public.ST_NumGeometries(geom) AS parts
             FROM zones WHERE id = ?',
            [$zone->id]
        );

        $this->assertSame('MULTIPOLYGON', $info->gtype);
        $this->assertGreaterThanOrEqual(2, (int) $info->parts);
    }

    #[Group('zone')]
    public function test_rebuild_geometry_with_empty_definition_clears_geom(): void
    {
        $zone = $this->makeZone([
            'polygons' => [[
                'type' => 'Polygon',
                'coordinates' => [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]],
            ]],
            'zip_codes' => [],
        ]);
        $zone->rebuildGeometryFromDefinition();
        $this->assertGeomPopulated($zone->id);

        $zone->update(['definition' => ['polygons' => [], 'zip_codes' => []]]);
        $zone->refresh();
        $zone->rebuildGeometryFromDefinition();

        $hasGeom = DB::connection('platform')
            ->table('zones')
            ->where('id', $zone->id)
            ->whereNotNull('geom')
            ->exists();

        $this->assertFalse($hasGeom, 'geom should be cleared when definition is empty');
    }

    #[Group('zone')]
    public function test_rebuild_geometry_skips_degenerate_polygons_without_throwing(): void
    {
        // A stray single-point "polygon" used to reach ST_GeomFromGeoJSON/ST_Union
        // and 500 the whole save; it must now be ignored, building geom from the
        // valid polygon instead.
        $zone = $this->makeZone([
            'polygons' => [
                ['type' => 'Polygon', 'coordinates' => [[[-101.09, 39.40]]]],
                ['type' => 'Polygon', 'coordinates' => [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]]],
            ],
            'zip_codes' => [],
        ]);

        $zone->rebuildGeometryFromDefinition();

        $this->assertGeomPopulated($zone->id);
        $this->assertSame('ST_MultiPolygon', $this->geomType($zone->id));
    }

    #[Group('zone')]
    public function test_rebuild_geometry_clears_geom_when_only_degenerate_polygons(): void
    {
        $zone = $this->makeZone([
            'polygons' => [['type' => 'Polygon', 'coordinates' => [[[-101.09, 39.40]]]]],
            'zip_codes' => [],
        ]);

        $zone->rebuildGeometryFromDefinition();

        $hasGeom = DB::connection('platform')
            ->table('zones')
            ->where('id', $zone->id)
            ->whereNotNull('geom')
            ->exists();

        $this->assertFalse($hasGeom, 'a definition with only degenerate polygons should clear geom');
    }

    #[Group('zone')]
    public function test_rebuild_geometry_with_unresolvable_zip_leaves_geom_null_without_throwing(): void
    {
        // Pins the never-throw contract: KmlZoneParser/ZoneKmlImporter and
        // ManageZones::save() all rely on this method never raising, and instead
        // guard the empty-geometry result themselves (DEV-2252).
        $zone = $this->makeZone([
            'polygons' => [],
            'zip_codes' => ['99999'],
        ]);

        $zone->rebuildGeometryFromDefinition();

        $hasGeom = DB::connection('platform')
            ->table('zones')
            ->where('id', $zone->id)
            ->whereNotNull('geom')
            ->exists();

        $this->assertFalse($hasGeom, 'an unresolvable ZIP should leave geom null, not throw');
    }

    #[Group('zone')]
    public function test_user_zone_pivot_is_cascaded_on_zone_delete(): void
    {
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();
        $user = User::factory()->forOrg($org)->forKit($kit)->create();

        $zone = Zone::create([
            'org_id' => $org->id,
            'name' => 'Cascade',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);
        $zone->users()->attach($user->id);

        $this->assertDatabaseHas('zone_user', ['zone_id' => $zone->id, 'user_id' => $user->id]);

        // Force-delete to exercise the FK cascade (soft delete keeps pivot rows by design).
        $zone->forceDelete();

        $this->assertDatabaseMissing('zone_user', ['zone_id' => $zone->id]);
    }

    #[Group('zone')]
    public function test_search_zip_codes_returns_matching_rows_with_geojson(): void
    {
        $this->seedTigerZip('10001', 'POLYGON((-74 40, -73 40, -73 41, -74 41, -74 40))');
        $this->seedTigerZip('10002', 'POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))');
        $this->seedTigerZip('20001', 'POLYGON((-77 38, -76 38, -76 39, -77 39, -77 38))');

        $results = Zone::searchZipCodes('100');

        $zips = array_column($results, 'zip');
        sort($zips);

        $this->assertSame(['10001', '10002'], $zips);
        $this->assertNotEmpty($results[0]['geojson']);
        $decoded = json_decode($results[0]['geojson'], true);
        $this->assertSame('MultiPolygon', $decoded['type'] ?? null);
    }

    #[Group('zone')]
    public function test_search_zip_codes_returns_empty_for_blank_query(): void
    {
        $this->assertSame([], Zone::searchZipCodes(''));
        $this->assertSame([], Zone::searchZipCodes('   '));
    }

    #[Group('zone')]
    public function test_is_no_scan_zone_defaults_to_false_and_casts_to_boolean(): void
    {
        $org = Org::factory()->create();

        $zone = Zone::create([
            'org_id' => $org->id,
            'name' => 'Default',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);

        $this->assertFalse($zone->is_no_scan_zone);

        $zone->update(['is_no_scan_zone' => 1]);

        $this->assertSame(true, $zone->fresh()->is_no_scan_zone);
    }

    #[Group('zone')]
    public function test_preset_palette_constants_are_defined(): void
    {
        $this->assertArrayHasKey(Zone::DEFAULT_COLOR, Zone::PRESET_COLORS);
        $this->assertSame('#2563eb', Zone::DEFAULT_COLOR);
    }

    #[Group('zone')]
    public function test_coerce_color_keeps_a_preset_color(): void
    {
        $this->assertSame('#0d9488', Zone::coerceColor('#0d9488'));
    }

    #[Group('zone')]
    public function test_coerce_color_snaps_out_of_palette_to_default(): void
    {
        $this->assertSame(Zone::DEFAULT_COLOR, Zone::coerceColor('#abcdef'));
    }

    #[Group('zone')]
    public function test_coerce_color_handles_null(): void
    {
        $this->assertSame(Zone::DEFAULT_COLOR, Zone::coerceColor(null));
    }

    private function makeZone(array $definition): Zone
    {
        $org = Org::factory()->create();

        return Zone::create([
            'org_id' => $org->id,
            'name' => 'Z',
            'color' => '#2563eb',
            'definition' => $definition,
        ]);
    }

    private function seedTigerZip(string $zip, string $wkt): void
    {
        // Test DB's spatial_ref_sys is pruned (see HasZodDatabase) and doesn't include
        // SRID 4269 (NAD83). zcta5_all.the_geom is declared with SRID 4269, and any
        // ST_Transform from 4269 needs the projection definition. Insert it if absent.
        DB::connection('zod')->statement(
            "INSERT INTO spatial_ref_sys (srid, auth_name, auth_srid, srtext, proj4text)
             VALUES (4269, 'EPSG', 4269,
                     'GEOGCS[\"NAD83\",DATUM[\"North_American_Datum_1983\",SPHEROID[\"GRS 1980\",6378137,298.257222101,AUTHORITY[\"EPSG\",\"7019\"]],TOWGS84[0,0,0,0,0,0,0],AUTHORITY[\"EPSG\",\"6269\"]],PRIMEM[\"Greenwich\",0,AUTHORITY[\"EPSG\",\"8901\"]],UNIT[\"degree\",0.0174532925199433,AUTHORITY[\"EPSG\",\"9122\"]],AUTHORITY[\"EPSG\",\"4269\"]]',
                     '+proj=longlat +datum=NAD83 +no_defs')
             ON CONFLICT (srid) DO NOTHING"
        );

        // tiger_data.zcta5_all inherits from tiger.zcta5 — insert via the parent's columns.
        // Cast the WKT placeholder to text so PG can resolve ST_GeomFromText(text, integer)
        // rather than the non-existent ST_GeomFromText(unknown, integer) signature.
        DB::connection('zod')->statement(
            'INSERT INTO tiger_data.zcta5_all (zcta5ce, statefp, the_geom)
             VALUES (?, ?, ST_Multi(ST_GeomFromText(?::text, 4269)))',
            [$zip, '48', $wkt]
        );
    }

    private function assertGeomPopulated(int $zoneId): void
    {
        $exists = DB::connection('platform')
            ->table('zones')
            ->where('id', $zoneId)
            ->whereNotNull('geom')
            ->exists();

        $this->assertTrue($exists, "zone {$zoneId} should have geom populated");
    }

    private function geomType(int $zoneId): string
    {
        return (string) DB::connection('platform')
            ->selectOne('SELECT public.ST_GeometryType(geom) AS t FROM zones WHERE id = ?', [$zoneId])
            ?->t;
    }
}
