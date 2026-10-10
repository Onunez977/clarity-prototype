<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\OrgMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class OrgMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(OrgMiddleware::class)->any('/billing', fn () => response(status: 200));
    }

    #[Group('middleware')]
    public function test_can_set_permission_team(): void
    {
        $this->actingAs(
            $user = User::factory()->create()
        );

        $this->assertNull(getPermissionsTeamId());

        $this->get('/billing')
            ->assertStatus(200);

        $this->assertNotNull(getPermissionsTeamId());
        $this->assertEquals($user->org_id, getPermissionsTeamId());
    }

    #[Group('middleware')]
    public function test_doesnt_set_permission_team_for_unathuenticated_user(): void
    {
        $this->assertNull(getPermissionsTeamId());

        $this->get('/billing')
            ->assertStatus(200);

        $this->assertNull(getPermissionsTeamId());
    }
}
