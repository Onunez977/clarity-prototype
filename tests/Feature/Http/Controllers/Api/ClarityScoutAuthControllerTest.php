<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\ScoutAnalyticsEvent;
use App\Facades\Analytics;
use App\Models\Kit;
use App\Models\Org;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use App\Notifications\ScoutOnboardingNotification;
use App\Notifications\ScoutPasswordResetNotification;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutAuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Analytics::shouldReceive('track')->zeroOrMoreTimes()->andReturnNull();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_login_with_valid_credentials(): void
    {
        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        $response = $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'org_id'],
                'kit' => ['id', 'name', 'kit_mapping'],
            ]);

        $this->assertEquals($user->id, $response->json('user.id'));
        $this->assertEquals($user->kit_id, $response->json('kit.id'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_relogin_revokes_prior_token(): void
    {
        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        $firstToken = $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSuccessful()->json('token');

        $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSuccessful();

        $this->assertSame(1, $user->tokens()->where('name', 'clarity-scout-token')->count());

        $this->withToken($firstToken)
            ->getJson('/api/scout/me')
            ->assertStatus(401);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_login_is_throttled_after_repeated_attempts(): void
    {
        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/scout/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_login_with_invalid_password(): void
    {
        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonFragment(['message' => 'Invalid credentials']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_login_without_clarity_scout_permission(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()->forOrg($org)->forKit($kit)->create([
            'password' => Hash::make('password'),
        ]);

        // No clarity-scout permission granted

        $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonFragment(['message' => 'You do not have portal access']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_login_without_kit_assigned(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);

        $user = User::factory()->forOrg($org)->create([
            'password' => Hash::make('password'),
        ]);

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonFragment(['message' => 'No scanner kit assigned to your account']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_login_without_clarity_scout_feature(): void
    {
        // Create org WITHOUT pro features
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()->forOrg($org)->forKit($kit)->create([
            'password' => Hash::make('password'),
        ]);

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_logout(): void
    {
        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/scout/logout')
            ->assertSuccessful()
            ->assertJsonFragment(['message' => 'Logged out successfully']);

        // Token should now be revoked — verify it's deleted from DB
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => $user->getMorphClass(),
        ]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_logout_does_not_track_analytics_but_revokes_token_for_force_login_impersonator(): void
    {
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        $user = $this->createScannerUser();
        $forceToken = $user->createToken('clarity-scout-force-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $this->withToken($forceToken)
            ->postJson('/api/scout/logout')
            ->assertSuccessful()
            ->assertJsonFragment(['message' => 'Logged out successfully']);

        $spy->shouldNotHaveReceived('track');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => $user->getMorphClass(),
            'name' => 'clarity-scout-force-token',
        ]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_logout_tracks_analytics_and_revokes_token_for_normal_driver(): void
    {
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/scout/logout')
            ->assertSuccessful()
            ->assertJsonFragment(['message' => 'Logged out successfully']);

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::Logout->value)
            ->once();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => $user->getMorphClass(),
            'name' => 'clarity-scout-token',
        ]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_me(): void
    {
        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/scout/me');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'org_id'],
                'kit' => ['id', 'name', 'kit_mapping'],
                'is_impersonating',
            ]);

        $this->assertEquals($user->id, $response->json('user.id'));
        $this->assertFalse($response->json('is_impersonating'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_marks_force_token_as_impersonating(): void
    {
        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertTrue($response->json('is_impersonating'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_login_reports_trips_disabled_by_default(): void
    {
        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        $response = $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSuccessful();
        $this->assertFalse($response->json('trips_enabled'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_login_reports_trips_enabled_when_flag_on(): void
    {
        config()->set('app.experimental.clarity_scout_trips', true);

        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        $response = $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSuccessful();
        $this->assertTrue($response->json('trips_enabled'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_reports_trips_disabled_by_default(): void
    {
        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertFalse($response->json('trips_enabled'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_reports_trips_enabled_when_flag_on(): void
    {
        config()->set('app.experimental.clarity_scout_trips', true);

        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertTrue($response->json('trips_enabled'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_reports_teammate_overlay_disabled_when_flag_off(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);

        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertFalse($response->json('teammate_overlay_enabled'));
        $this->assertNotContains('teammates', $response->json('enabled_map_layers'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_reports_teammate_overlay_enabled_when_flag_on(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertTrue($response->json('teammate_overlay_enabled'));
        $this->assertEqualsCanonicalizing(
            ['hits', 'hotlist', 'staging', 'teammates'],
            $response->json('enabled_map_layers')
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_reports_teammate_overlay_enabled_for_impersonator_even_when_flag_off(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);

        $user = $this->createScannerUser();
        $token = $user->createToken('clarity-scout-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertTrue($response->json('is_impersonating'));
        $this->assertTrue($response->json('teammate_overlay_enabled'));
        $this->assertContains('teammates', $response->json('enabled_map_layers'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_omits_org_disabled_map_layers(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $user = $this->createScannerUser();
        ScoutOrgSettings::factory()->create([
            'org_id' => $user->org_id,
            'map_layers' => ScoutOrgSettings::mapFromEnabled(['hits', 'teammates']),
        ]);
        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertEqualsCanonicalizing(['hits', 'teammates'], $response->json('enabled_map_layers'));
        $this->assertTrue($response->json('teammate_overlay_enabled'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_me_omits_teammates_when_org_disables_layer_even_for_impersonator(): void
    {
        $user = $this->createScannerUser();
        ScoutOrgSettings::factory()->create([
            'org_id' => $user->org_id,
            'map_layers' => ScoutOrgSettings::mapFromEnabled(['hits', 'hotlist', 'staging']),
        ]);
        $token = $user->createToken('clarity-scout-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/scout/me');

        $response->assertSuccessful();
        $this->assertTrue($response->json('is_impersonating'));
        $this->assertFalse($response->json('teammate_overlay_enabled'));
        $this->assertNotContains('teammates', $response->json('enabled_map_layers'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_login_reports_teammate_overlay_disabled_when_flag_off(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);

        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        $response = $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSuccessful();
        $this->assertFalse($response->json('teammate_overlay_enabled'));
        $this->assertNotContains('teammates', $response->json('enabled_map_layers'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_login_reports_teammate_overlay_enabled_when_flag_on(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $user = $this->createScannerUser(['password' => Hash::make('password')]);

        $response = $this->postJson('/api/scout/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSuccessful();
        $this->assertTrue($response->json('teammate_overlay_enabled'));
        $this->assertEqualsCanonicalizing(
            ['hits', 'hotlist', 'staging', 'teammates'],
            $response->json('enabled_map_layers')
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_login_validates_required_fields(): void
    {
        $this->postJson('/api/scout/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_login_with_nonexistent_email(): void
    {
        $this->postJson('/api/scout/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertStatus(401)
            ->assertJsonFragment(['message' => 'Invalid credentials']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_forgot_password_sends_reset_for_verified_scout_user(): void
    {
        Notification::fake();

        $user = $this->createScannerUser([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/scout/forgot-password', [
            'email' => $user->email,
        ])
            ->assertSuccessful()
            ->assertJsonFragment([
                'message' => 'If that email is registered for Clarity Scout, we sent a reset link.',
            ]);

        Notification::assertSentTo($user, ScoutPasswordResetNotification::class);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_forgot_password_sends_onboarding_for_unverified_scout_user(): void
    {
        Notification::fake();

        $user = $this->createScannerUser([
            'email_verified_at' => null,
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/scout/forgot-password', [
            'email' => $user->email,
        ])->assertSuccessful();

        Notification::assertSentTo($user, ScoutOnboardingNotification::class);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson('/api/scout/forgot-password', [
            'email' => 'nobody@example.com',
        ])
            ->assertSuccessful()
            ->assertJsonFragment([
                'message' => 'If that email is registered for Clarity Scout, we sent a reset link.',
            ]);

        Notification::assertNothingSent();
    }

    private function createScannerUser(array $userAttrs = []): User
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()->forOrg($org)->forKit($kit)->create($userAttrs);

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        return $user;
    }
}
