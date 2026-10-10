<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\FeatureEnum;
use App\Models\Org;
use App\Models\User;
use Filament\Facades\Filament;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Scout org-map admin route group — client-side routing leak (DEV-2184). An
 * ungated driver-only fetch under `/scout/org-map/api/*` with no admin-route
 * mirror was falling through to the group's Vue-Router SPA catch-all, which
 * returns the Blade shell (200 text/html) instead of a JSON 404 — the fetch
 * then throws on `response.json()` parsing the leading `<`.
 *
 * Postgres-only: no ClickHouse involvement in either the routing fallback
 * (a plain closure) or the SPA shell (a plain view).
 */
class ClarityScoutOrgMapRoutingTest extends TestCase
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

    public function test_unmatched_admin_api_path_returns_json_404_not_the_html_shell(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/shift/status')
            ->assertStatus(404);

        $response->assertHeader('content-type', 'application/json');
    }

    public function test_disposition_post_route_is_reachable_and_json_not_the_html_shell(): void
    {
        // Empty body is enough to prove the route resolved and the controller
        // ran (a validation failure), without re-testing the controller's own
        // business logic, which is already pinned in
        // ClarityScoutBirdsEyeDispositionControllerTest.
        $response = $this->actingAs($this->admin)
            ->postJson('/scout/org-map/api/scan/disposition', [])
            ->assertStatus(422);

        $response->assertHeader('content-type', 'application/json');
    }

    public function test_disposition_post_route_is_gated_from_unauthenticated_and_non_admin_users(): void
    {
        $this->postJson('/scout/org-map/api/scan/disposition', [])
            ->assertStatus(401);

        $nonAdmin = User::factory()->forOrg($this->org)->create();
        setPermissionsTeamId($this->org->id);

        $this->actingAs($nonAdmin)
            ->postJson('/scout/org-map/api/scan/disposition', [])
            ->assertStatus(403);
    }

    public function test_spa_deep_link_outside_api_still_returns_the_html_shell(): void
    {
        $this->actingAs($this->admin)
            ->get('/scout/org-map/live')
            ->assertOk()
            ->assertSee('clarity-scout-app', false);
    }

    public function test_org_map_shell_injects_back_url_to_the_clarity_panel(): void
    {
        $backUrl = Filament::getPanel('app')->getUrl();

        $this->actingAs($this->admin)
            ->get('/scout/org-map')
            ->assertOk()
            ->assertSee("backUrl: '{$backUrl}'", false);
    }
}
