<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    #[Group('middleware')]
    #[Group('clarity-scout')]
    public function test_rejects_unauthenticated_request(): void
    {
        $this->getJson('/api/scout/me')
            ->assertStatus(401);
    }

    #[Group('middleware')]
    #[Group('clarity-scout')]
    public function test_rejects_user_without_permission(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()->forOrg($org)->forKit($kit)->create();

        // No clarity-scout permission
        $token = $user->createToken('test', ['clarity-scout'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/scout/me')
            ->assertStatus(401)
            ->assertJsonFragment(['message' => 'You do not have portal access']);
    }

    #[Group('middleware')]
    #[Group('clarity-scout')]
    public function test_rejects_user_without_kit(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);

        $user = User::factory()->forOrg($org)->create();

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        $token = $user->createToken('test', ['clarity-scout'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/scout/me')
            ->assertStatus(401)
            ->assertJsonFragment(['message' => 'No scanner kit assigned to your account']);
    }

    #[Group('middleware')]
    #[Group('clarity-scout')]
    public function test_rejects_user_without_feature(): void
    {
        // Create org WITHOUT pro features
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()->forOrg($org)->forKit($kit)->create();

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        $token = $user->createToken('test', ['clarity-scout'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/scout/me')
            ->assertStatus(401)
            ->assertJsonFragment(['message' => 'Clarity Scout is not enabled']);
    }

    #[Group('middleware')]
    #[Group('clarity-scout')]
    public function test_allows_valid_clarity_scout_user(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()->forOrg($org)->forKit($kit)->create();

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        $token = $user->createToken('test', ['clarity-scout'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/scout/me')
            ->assertSuccessful();
    }

    #[Group('middleware')]
    #[Group('clarity-scout')]
    public function test_force_token_bypasses_permission_kit_and_feature_gates(): void
    {
        // A user with NO permission, NO kit, and NO Pro feature — but an
        // admin impersonation token carrying the literal clarity-scout-force
        // ability — clears every gate. Pins the documented force-login bypass
        // (ClarityScoutMiddleware:25) so a refactor can't silently remove it.
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();

        $token = $user->createToken('clarity-scout-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/scout/me')
            ->assertSuccessful();
    }
}
