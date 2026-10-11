<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use Tests\ResourceTestCase;

class ZoneIndexControllerTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
    }

    #[Group('controllers')]
    #[Group('zones')]
    public function test_zones_all_forbidden_for_non_admin(): void
    {
        $nonAdmin = $this->createUser();

        $this->actingAs($nonAdmin)
            ->getJson('/zones/all')
            ->assertForbidden();
    }

    #[Group('controllers')]
    #[Group('zones')]
    public function test_zones_all_forbidden_when_clarity_scout_disabled(): void
    {
        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();

        $this->getJson('/zones/all')->assertForbidden();
    }

    #[Group('controllers')]
    #[Group('zones')]
    public function test_zones_all_forbidden_for_pro_org_without_explicit_scout_grant(): void
    {
        // The Zone map's data feed must honor the same explicit-grant gate as the
        // ManageZones page (DEV-2115): a Clarity-Pro admin with no individual
        // clarity_scout grant cannot read zone data, even via direct AJAX.
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::FLEX_SEARCH->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($admin->org_id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin);
        Feature::flushCache();

        $this->getJson('/zones/all')->assertForbidden();
    }

    #[Group('controllers')]
    #[Group('zones')]
    public function test_zones_all_allowed_for_admin_impersonator_without_scout_grant(): void
    {
        // DEV-2164: an admin impersonating into an org WITHOUT the clarity_scout
        // grant must still reach the zone data feed, matching the ManageZones page
        // gate. Same fixture as the forbidden case above, plus the impersonator flag.
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::FLEX_SEARCH->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($admin->org_id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin);
        Feature::flushCache();

        $this->withSession(['admin_impersonator' => $admin->id])
            ->getJson('/zones/all')
            ->assertSuccessful();
    }

    #[Group('controllers')]
    #[Group('zones')]
    public function test_zones_all_requires_authentication(): void
    {
        auth()->logout();

        $this->getJson('/zones/all')->assertUnauthorized();
    }

    #[Group('controllers')]
    #[Group('zones')]
    public function test_zones_all_response_shape_matches_frontend_contract(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $kitUser = User::factory()->forOrg($this->user->org)->forKit($kit)->create();

        $zone = $this->createZone($this->user->org_id, 'Shape Check');
        $zone->description = 'A zone with assigned drivers.';
        $zone->definition = [
            'polygons' => [[
                'type' => 'Polygon',
                'coordinates' => [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]],
            ]],
            'zip_codes' => [],
        ];
        $zone->save();
        $zone->rebuildGeometryFromDefinition();
        $zone->users()->attach($kitUser->id);

        $feature = $this->getJson('/zones/all')
            ->assertSuccessful()
            ->json('zones.0');

        $this->assertSame('Feature', $feature['type']);
        $this->assertSame($zone->id, $feature['id']);
        $this->assertNotNull($feature['geometry']);

        $props = $feature['properties'];
        foreach (['id', 'name', 'description', 'color', 'is_no_scan_zone', 'definition', 'centroid', 'user_ids', 'user_names'] as $key) {
            $this->assertArrayHasKey($key, $props, "missing properties.$key");
        }

        $this->assertSame('Shape Check', $props['name']);
        $this->assertSame('A zone with assigned drivers.', $props['description']);
        $this->assertContains($kitUser->id, $props['user_ids']);
        $this->assertContains($kitUser->name, $props['user_names']);
        $this->assertIsArray($props['centroid']);
        $this->assertCount(2, $props['centroid']);
    }

    private function createZone(int $orgId, string $name): Zone
    {
        return Zone::create([
            'org_id' => $orgId,
            'name' => $name,
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);
    }
}
