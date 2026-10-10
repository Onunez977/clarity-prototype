<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Kit;
use App\Models\Org;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Admin "Login to Scout" impersonation (DEV-1916 §5, TC-A5).
 */
class ClarityScoutForceLoginControllerTest extends TestCase
{
    private Org $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $this->org = Org::factory()->create();
        $this->admin = User::factory()->forOrg($this->org)->create();

        setPermissionsTeamId($this->org->id);
        $this->admin->assignRole(RoleEnum::ADMIN->value);
    }

    private function forceLogin(Kit $kit): TestResponse
    {
        return $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertOk();
    }

    private function kitWithDriver(): Kit
    {
        $kit = Kit::factory()->forOrg($this->org)->create();
        User::factory()->forOrg($this->org)->forKit($kit)->create();

        return $kit;
    }

    #[Group('clarity-scout')]
    public function test_mints_a_force_token_without_evicting_the_drivers_live_token(): void
    {
        $kit = $this->kitWithDriver();
        $driver = $kit->assigned_user;

        $driver->createToken('clarity-scout-token', ['*']);

        $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertOk();

        $driver->refresh();

        $live = $driver->tokens()->where('name', 'clarity-scout-token')->get();
        $this->assertCount(1, $live);
        $this->assertNotContains('clarity-scout-force', $live->first()->abilities);

        $force = $driver->tokens()->where('name', 'clarity-scout-force-token')->get();
        $this->assertCount(1, $force);
        $this->assertContains('clarity-scout-force', $force->first()->abilities);
    }

    /**
     * One click can produce more than one GET (browser prefetch, double-click,
     * reload). Evicting the prior token would revoke the one embedded in the
     * page the staff user is looking at, bouncing it to login on its first poll.
     */
    #[Group('clarity-scout')]
    public function test_re_login_leaves_the_previously_issued_token_usable(): void
    {
        $kit = $this->kitWithDriver();
        $driver = $kit->assigned_user;

        $first = $this->forceLogin($kit)->viewData('forceAuth')['token'];
        $this->forceLogin($kit);

        $stillLive = PersonalAccessToken::findToken($first);

        $this->assertNotNull($stillLive);
        $this->assertTrue($stillLive->expires_at->isFuture());

        $this->assertCount(
            2,
            $driver->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get()
        );
    }

    #[Group('clarity-scout')]
    public function test_prunes_force_tokens_beyond_the_retained_window(): void
    {
        $kit = $this->kitWithDriver();
        $driver = $kit->assigned_user;

        foreach (range(1, 5) as $ignored) {
            $this->forceLogin($kit);
        }

        $this->assertCount(
            3,
            $driver->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get()
        );
    }

    #[Group('clarity-scout')]
    public function test_prunes_expired_force_tokens_on_the_next_login(): void
    {
        $kit = $this->kitWithDriver();
        $driver = $kit->assigned_user;

        $this->forceLogin($kit);

        $driver->tokens()
            ->where('name', 'clarity-scout-force-token')
            ->update(['expires_at' => now()->subMinute()]);

        $this->forceLogin($kit);

        $live = $driver->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get();

        $this->assertCount(1, $live);
        $this->assertTrue($live->first()->expires_at->isFuture());
    }

    #[Group('clarity-scout')]
    public function test_force_token_is_minted_with_a_short_expiry(): void
    {
        $kit = $this->kitWithDriver();
        $driver = $kit->assigned_user;

        $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertOk();

        $force = $driver->fresh()->tokens()->where('name', 'clarity-scout-force-token')->first();

        $this->assertNotNull($force->expires_at);
        $this->assertTrue($force->expires_at->isAfter(now()->addMinutes(58)));
        $this->assertTrue($force->expires_at->isBefore(now()->addMinutes(62)));
    }

    #[Group('clarity-scout')]
    public function test_forbidden_without_an_impersonator_session(): void
    {
        $kit = $this->kitWithDriver();

        $this->actingAs($this->admin)
            ->get(route('clarity-scout.force-login', $kit))
            ->assertForbidden();
    }

    #[Group('clarity-scout')]
    public function test_not_found_when_kit_has_no_assigned_user(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertNotFound();
    }

    #[Group('clarity-scout')]
    public function test_enables_teammate_overlay_regardless_of_config(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);

        $kit = $this->kitWithDriver();

        $response = $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertOk();

        $this->assertTrue($response->viewData('forceAuth')['teammate_overlay_enabled']);
        $this->assertContains('teammates', $response->viewData('forceAuth')['enabled_map_layers']);
    }

    #[Group('clarity-scout')]
    public function test_hides_teammates_when_org_disables_layer_even_for_impersonators(): void
    {
        ScoutOrgSettings::factory()->layers(['hits', 'hotlist', 'staging'])->create([
            'org_id' => $this->org->id,
        ]);

        $kit = $this->kitWithDriver();

        $response = $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertOk();

        $this->assertFalse($response->viewData('forceAuth')['teammate_overlay_enabled']);
        $this->assertNotContains('teammates', $response->viewData('forceAuth')['enabled_map_layers']);
    }

    #[Group('clarity-scout')]
    public function test_writes_an_audit_log_record_for_the_force_login(): void
    {
        $kit = $this->kitWithDriver();
        $driver = $kit->assigned_user;

        $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'clarity-scout-force-login',
            'subject_id' => $driver->id,
        ]);
    }

    #[Group('clarity-scout')]
    public function test_consecutive_force_logins_for_different_drivers_keep_all_tokens_alive(): void
    {
        $kits = [
            $this->kitWithDriver(),
            $this->kitWithDriver(),
            $this->kitWithDriver(),
        ];

        $tokens = [];

        foreach ($kits as $kit) {
            $tokens[] = $this->forceLogin($kit)->viewData('forceAuth')['token'];
        }

        foreach ($tokens as $plain) {
            $this->assertNotNull(PersonalAccessToken::findToken($plain));
        }

        foreach ($kits as $kit) {
            $this->assertCount(
                1,
                $kit->assigned_user->fresh()->tokens()->where('name', 'clarity-scout-force-token')->get()
            );
        }
    }

    #[Group('clarity-scout')]
    public function test_forbidden_without_impersonator_writes_a_named_denied_audit_row(): void
    {
        $kit = $this->kitWithDriver();

        $response = $this->actingAs($this->admin)
            ->get(route('clarity-scout.force-login', $kit));

        $response->assertForbidden();
        $this->assertStringContainsString('Admin mode is not active', $response->getContent());

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'clarity-scout-force-login',
        ]);

        $row = Activity::query()
            ->where('log_name', 'clarity-scout-force-login')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('denied', data_get($row->properties, 'outcome'));
        $this->assertSame('admin_impersonator_missing', data_get($row->properties, 'reason'));
    }

    #[Group('clarity-scout')]
    public function test_not_found_without_assigned_user_writes_a_denied_audit_row(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $this->actingAs($this->admin)
            ->withSession(['admin_impersonator' => $this->admin->id])
            ->get(route('clarity-scout.force-login', $kit))
            ->assertNotFound();

        $row = Activity::query()
            ->where('log_name', 'clarity-scout-force-login')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('denied', data_get($row->properties, 'outcome'));
        $this->assertSame('kit_has_no_assigned_user', data_get($row->properties, 'reason'));
    }
}
