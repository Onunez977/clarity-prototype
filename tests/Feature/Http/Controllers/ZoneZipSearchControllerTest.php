<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ZoneZipSearchControllerTest extends TestCase
{
    #[Group('controllers')]
    #[Group('zone')]
    public function test_returns_matching_zip_codes_with_geojson(): void
    {
        $this->seedTigerZip('30301', 'POLYGON((-84.4 33.7, -84.3 33.7, -84.3 33.8, -84.4 33.8, -84.4 33.7))');
        $this->seedTigerZip('30302', 'POLYGON((-84.3 33.7, -84.2 33.7, -84.2 33.8, -84.3 33.8, -84.3 33.7))');
        $this->seedTigerZip('40404', 'POLYGON((-85 35, -84 35, -84 36, -85 36, -85 35))');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $response = $this->actingAs($user)->getJson('/zones/zip-search?q=303');

        $response->assertSuccessful();

        $zips = collect($response->json())->pluck('zip')->sort()->values()->all();
        $this->assertSame(['30301', '30302'], $zips);

        $first = $response->json('0');
        $this->assertArrayHasKey('geojson', $first);
        $this->assertNotEmpty($first['geojson']);
    }

    #[Group('controllers')]
    #[Group('zone')]
    public function test_returns_forbidden_for_non_admin_user(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->getJson('/zones/zip-search?q=303')
            ->assertForbidden();
    }

    #[Group('controllers')]
    #[Group('zone')]
    public function test_returns_forbidden_for_pro_org_without_explicit_scout_grant(): void
    {
        // Mirror the ManageZones page gate (DEV-2115): a Clarity-Pro admin without an
        // explicit clarity_scout grant cannot reach the zip-search data endpoint.
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::FLEX_SEARCH->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($admin->org_id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin);
        Feature::flushCache();

        $this->getJson('/zones/zip-search?q=303')->assertForbidden();
    }

    #[Group('controllers')]
    #[Group('zone')]
    public function test_allowed_for_admin_impersonator_without_scout_grant(): void
    {
        // DEV-2164: an admin impersonating into an org WITHOUT the clarity_scout
        // grant must still reach the zip-search endpoint, matching the ManageZones
        // page gate. Same fixture as the forbidden case above, plus the flag.
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::FLEX_SEARCH->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($admin->org_id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin);
        Feature::flushCache();

        $this->withSession(['admin_impersonator' => $admin->id])
            ->getJson('/zones/zip-search?q=303')
            ->assertSuccessful();
    }

    #[Group('controllers')]
    #[Group('zone')]
    public function test_requires_q_parameter(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $this->actingAs($user)
            ->getJson('/zones/zip-search')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    #[Group('controllers')]
    #[Group('zone')]
    public function test_rejects_q_longer_than_five_chars(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $this->actingAs($user)
            ->getJson('/zones/zip-search?q=123456')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    #[Group('controllers')]
    #[Group('zone')]
    public function test_requires_authentication(): void
    {
        $this->get('/zones/zip-search?q=303')
            ->assertStatus(302); // web auth middleware redirects unauthenticated
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

        // Cast the WKT placeholder to text so PG resolves ST_GeomFromText(text, integer)
        // rather than the non-existent ST_GeomFromText(unknown, integer) signature.
        DB::connection('zod')->statement(
            'INSERT INTO tiger_data.zcta5_all (zcta5ce, statefp, the_geom)
             VALUES (?, ?, ST_Multi(ST_GeomFromText(?::text, 4269)))',
            [$zip, '13', $wkt]
        );
    }
}
