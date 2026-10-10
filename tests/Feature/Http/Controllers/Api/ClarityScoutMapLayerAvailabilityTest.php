<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Kit;
use App\Models\Org;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutMapLayerAvailabilityTest extends TestCase
{
    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();

        $this->user = User::factory()->forOrg($org)->forKit($kit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_empty_when_org_disables_layer(): void
    {
        ScoutOrgSettings::factory()->layers(['hits', 'hotlist', 'staging'])->create([
            'org_id' => $this->user->org_id,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/teammate-paths')
            ->assertSuccessful()
            ->assertExactJson([]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_hits_empty_when_org_disables_layer(): void
    {
        ScoutOrgSettings::factory()->layers(['hotlist', 'staging', 'teammates'])->create([
            'org_id' => $this->user->org_id,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=31&south=30&east=-97&west=-98')
            ->assertSuccessful()
            ->assertJson([
                'type' => 'FeatureCollection',
                'features' => [],
            ]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_orders_drops_disabled_order_layers(): void
    {
        ScoutOrgSettings::factory()->layers(['hits', 'hotlist', 'teammates'])->create([
            'org_id' => $this->user->org_id,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=31&south=30&east=-97&west=-98&layers[]=staging')
            ->assertSuccessful()
            ->assertJson([
                'type' => 'FeatureCollection',
                'features' => [],
            ]);
    }
}
