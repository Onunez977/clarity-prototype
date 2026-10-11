<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\FeatureEnum;
use App\Models\Org;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutBirdsEyeMapLayersTest extends TestCase
{
    private Org $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $this->org = Org::factory()->create();
        $this->makeOrgPro($this->org, FeatureEnum::CLARITY_SCOUT->value);

        $this->admin = User::factory()->forOrg($this->org)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);
        setPermissionsTeamId($this->org->id);
        $this->admin->assignRole(RoleEnum::ADMIN->value);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_get_returns_all_layers_by_default(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/settings/map-layers')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['hits', 'hotlist', 'staging', 'teammates'],
            $response->json('enabled')
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_put_persists_org_layer_availability(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson('/scout/org-map/api/settings/map-layers', [
                'enabled' => ['hits', 'teammates'],
            ])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['hits', 'teammates'],
            $response->json('enabled')
        );

        $this->assertEqualsCanonicalizing(
            ['hits', 'teammates'],
            ScoutOrgSettings::storedEnabledLayers($this->org->id)
        );

        $this->assertEqualsCanonicalizing(
            ['hits', 'teammates'],
            $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/settings/map-layers')
                ->assertSuccessful()
                ->json('enabled')
        );

        $this->org->unsetRelation('scoutOrgSettings');
        $this->assertNotNull($this->org->scoutOrgSettings);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_put_empty_list_hides_every_layer(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/scout/org-map/api/settings/map-layers', [
                'enabled' => [],
            ])
            ->assertSuccessful()
            ->assertJson(['enabled' => []]);

        $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/settings/map-layers')
            ->assertSuccessful()
            ->assertJson(['enabled' => []]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_unauthenticated_is_unauthorized(): void
    {
        $this->getJson('/scout/org-map/api/settings/map-layers')
            ->assertStatus(401);

        $this->putJson('/scout/org-map/api/settings/map-layers', [
            'enabled' => ['hits'],
        ])->assertStatus(401);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_put_rejects_unknown_layers(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/scout/org-map/api/settings/map-layers', [
                'enabled' => ['hits', 'not-a-layer'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['enabled.1']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_non_admin_is_forbidden(): void
    {
        $driver = User::factory()->forOrg($this->org)->create();
        setPermissionsTeamId($this->org->id);

        $this->actingAs($driver)
            ->getJson('/scout/org-map/api/settings/map-layers')
            ->assertStatus(403);

        $this->actingAs($driver)
            ->putJson('/scout/org-map/api/settings/map-layers', [
                'enabled' => ['hits'],
            ])
            ->assertStatus(403);
    }
}
