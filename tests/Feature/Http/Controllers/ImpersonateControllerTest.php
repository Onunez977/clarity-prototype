<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use InsightLPR\BlackAdam\BlackAdam;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImpersonateControllerTest extends TestCase
{
    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_can_impersonate(): void
    {
        $this->actingAs(
            $admin = $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $user = User::factory()->create();

        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $signedUrl = BlackAdam::generateSignedUrl(route('impersonate'), [
            'user_id' => $user->id,
            'impersonator' => $admin->only(['id', 'name']),
        ]);

        $this->get($signedUrl)
            ->assertRedirectToRoute('login');

        $this->assertTrue(session()->has('impersonator'));
        $this->assertEquals($admin->only(['id', 'name']), session()->get('impersonator'));
        $this->assertAuthenticatedAs($user);
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_cannot_impersonate_from_invalid_signature(): void
    {
        $this->get(route('impersonate'))
            ->assertStatus(401);

        $this->assertTrue(session()->missing('impersonator'));
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_cannot_impersonate_without_permissions(): void
    {
        $this->actingAs(
            $admin = $this->createUser()
        );

        $user = User::factory()->create();

        Config::set('black-adam.INSIGHT_LPR_KEY', 'invalid');

        $signedUrl = BlackAdam::generateSignedUrl(route('impersonate'), [
            'user_id' => $user->id,
            'impersonator' => $admin->only(['id', 'name']),
        ]);

        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $this->get($signedUrl)
            ->assertStatus(401);

        $this->assertTrue(session()->missing('impersonator'));
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_can_leave_impersonation(): void
    {
        $admin = $this->createUser(role: RoleEnum::ADMIN->value);
        $user = User::factory()->create();

        $this->actingAs($user);

        session()->put('impersonator', $admin->only(['id', 'name']));

        $this->get(route('impersonate.leave'))
            ->assertRedirect(config('black-adam.BACKYARD_URL').'/admin/platform-users');

        $this->assertFalse(session()->has('impersonator'));
        $this->assertNull(auth()->user());
    }

    /**
     * The force token belongs to the DRIVER, not to the staff session holding
     * the impersonation — revoking via $request->user() reached the wrong
     * account and left the bypass credential live for its full TTL.
     */
    private function forceLoginDriver(): User
    {
        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $org = Org::factory()->create();
        $admin = User::factory()->forOrg($org)->create();

        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $kit = Kit::factory()->forOrg($org)->create();
        $driver = User::factory()->forOrg($org)->forKit($kit)->create();

        $this->actingAs($admin);
        session()->put('admin_impersonator', $admin->only(['id', 'name']));

        $this->get(route('clarity-scout.force-login', $kit))->assertOk();

        $this->assertCount(1, $driver->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get());

        return $driver;
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_leaving_impersonation_revokes_the_drivers_force_token_but_keeps_normal_tokens(): void
    {
        $driver = $this->forceLoginDriver();
        $driver->createToken('clarity-scout-token', ['*']);

        session()->put('impersonator', ['id' => 1, 'name' => 'Staff']);

        $this->get(route('impersonate.leave'))
            ->assertRedirect(config('black-adam.BACKYARD_URL').'/admin/platform-users');

        $tokens = $driver->fresh()->tokens()->pluck('name');
        $this->assertNotContains('clarity-scout-force-token', $tokens);
        $this->assertContains('clarity-scout-token', $tokens);
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_exiting_admin_mode_revokes_the_drivers_force_token(): void
    {
        $driver = $this->forceLoginDriver();

        $this->post(route('impersonate.exit-admin'))
            ->assertRedirect();

        $this->assertCount(0, $driver->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get());
        $this->assertTrue(session()->missing('admin_impersonator'));
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_exit_admin_mode_get_does_not_revoke_or_clear_admin_mode(): void
    {
        $driver = $this->forceLoginDriver();

        // POST-only route: a GET must not run exitAdminMode (prefetch / history
        // replay must not silently kill force-login). Framework may 405 or
        // bounce elsewhere — either way tokens and the gate stay intact.
        $this->get(route('impersonate.exit-admin'));

        $this->assertTrue(session()->has('admin_impersonator'));
        $this->assertCount(1, $driver->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get());
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_exit_admin_mode_writes_an_activity_row(): void
    {
        $this->forceLoginDriver();

        $this->post(route('impersonate.exit-admin'))
            ->assertRedirect();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'impersonation',
        ]);

        $row = Activity::query()
            ->where('log_name', 'impersonation')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('exit_admin_mode', data_get($row->properties, 'action'));
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_force_login_after_exit_admin_mode_is_denied_with_an_explicit_reason(): void
    {
        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $org = Org::factory()->create();
        $admin = User::factory()->forOrg($org)->create();

        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $kit = Kit::factory()->forOrg($org)->create();
        User::factory()->forOrg($org)->forKit($kit)->create();

        $this->actingAs($admin);
        session()->put('admin_impersonator', $admin->only(['id', 'name']));

        $this->get(route('clarity-scout.force-login', $kit))->assertOk();

        $this->post(route('impersonate.exit-admin'))->assertRedirect();

        $response = $this->actingAs($admin)
            ->get(route('clarity-scout.force-login', $kit));

        $response->assertForbidden();
        $this->assertStringContainsString('Admin mode is not active', $response->getContent());
    }

    #[Group('controllers')]
    #[Group('impersonate')]
    #[Group('impersonation')]
    public function test_re_impersonation_still_revokes_force_tokens_across_drivers(): void
    {
        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $org = Org::factory()->create();
        $admin = User::factory()->forOrg($org)->create();

        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $kitA = Kit::factory()->forOrg($org)->create();
        $driverA = User::factory()->forOrg($org)->forKit($kitA)->create();
        $kitB = Kit::factory()->forOrg($org)->create();
        $driverB = User::factory()->forOrg($org)->forKit($kitB)->create();

        $this->actingAs($admin);
        session()->put('admin_impersonator', $admin->only(['id', 'name']));
        session()->put('impersonator', $admin->only(['id', 'name']));

        $this->get(route('clarity-scout.force-login', $kitA))->assertOk();
        $this->get(route('clarity-scout.force-login', $kitB))->assertOk();

        $this->assertCount(1, $driverA->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get());
        $this->assertCount(1, $driverB->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get());

        $nextUser = User::factory()->forOrg($org)->create();

        $signedUrl = BlackAdam::generateSignedUrl(route('impersonate'), [
            'user_id' => $nextUser->id,
            'impersonator' => $admin->only(['id', 'name']),
        ]);

        $this->get($signedUrl)->assertRedirectToRoute('login');

        $this->assertCount(0, $driverA->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get());
        $this->assertCount(0, $driverB->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get());
    }
}
