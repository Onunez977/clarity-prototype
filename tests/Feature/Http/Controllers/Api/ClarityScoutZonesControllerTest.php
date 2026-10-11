<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Facades\Analytics;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutZonesControllerTest extends TestCase
{
    private User $user;

    private Org $org;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Analytics::shouldReceive('track')->zeroOrMoreTimes()->andReturnNull();

        $this->org = Org::factory()->create();
        $this->makeOrgPro($this->org);

        $kit = Kit::factory()->forOrg($this->org)->create();
        $this->user = User::factory()
            ->forOrg($this->org)
            ->forKit($kit)
            ->create(['dti' => ['timeZone' => 'UTC']]);

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_returns_empty_list_when_user_has_no_zones(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/zones');

        $response->assertSuccessful()
            ->assertExactJson([
                'assigned' => [],
            ]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_returns_only_assigned_zones_not_neighboring_ones(): void
    {
        $assigned = $this->createZoneWithGeom($this->org->id, 'Home', '#2563eb', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
        $this->createZoneWithGeom($this->org->id, 'Touches', '#10b981', 'POLYGON((1 0, 1 1, 2 1, 2 0, 1 0))');
        $this->createZoneWithGeom($this->org->id, 'Far', '#f59e0b', 'POLYGON((10 10, 10 11, 11 11, 11 10, 10 10))');

        $this->user->zones()->attach($assigned->id);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/zones');

        $response->assertSuccessful();

        // A neighboring zone that touches the assigned one is no longer surfaced.
        $this->assertCount(1, $response->json('assigned'));
        $this->assertSame($assigned->id, $response->json('assigned.0.id'));
        $this->assertSame('Home', $response->json('assigned.0.properties.name'));
        $this->assertArrayNotHasKey('adjacent', $response->json());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_no_scan_zone_serializes_with_gray_color(): void
    {
        $assigned = $this->createZoneWithGeom(
            $this->org->id,
            'Off Limits',
            Zone::NO_SCAN_COLOR,
            'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))'
        );
        $assigned->update(['is_no_scan_zone' => true]);

        $this->user->zones()->attach($assigned->id);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/zones');

        $response->assertSuccessful();

        $this->assertCount(1, $response->json('assigned'));
        $this->assertSame(Zone::NO_SCAN_COLOR, $response->json('assigned.0.properties.color'));
    }

    /**
     * Contract: lock the GeoJSON Feature shape the PWA map consumes. Assigned
     * features carry `description`. A shape change fails CI rather than
     * silently breaking the (untested) Vue map layer.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_zone_feature_response_shape_is_locked(): void
    {
        $assigned = $this->createZoneWithGeom($this->org->id, 'Home', '#2563eb', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
        $this->user->zones()->attach($assigned->id);

        $this->withToken($this->token)->getJson('/api/scout/map/zones')
            ->assertSuccessful()
            ->assertJsonStructure([
                'assigned' => [[
                    'type', 'id',
                    'properties' => ['id', 'name', 'color', 'is_no_scan_zone', 'centroid', 'description'],
                    'geometry',
                ]],
            ]);
    }

    private function createZoneWithGeom(int $orgId, string $name, string $color, string $wkt): Zone
    {
        $zone = Zone::create([
            'org_id' => $orgId,
            'name' => $name,
            'color' => $color,
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);

        // PostGIS lives in `public`; platform connection's search_path is `platform` only.
        // Cast the WKT placeholder to text so PG resolves ST_GeomFromText(text, integer).
        DB::connection('platform')->statement(
            'UPDATE zones SET geom = public.ST_Multi(public.ST_GeomFromText(?::text, 4326)) WHERE id = ?',
            [$wkt, $zone->id]
        );

        return $zone->fresh();
    }
}
