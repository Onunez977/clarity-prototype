<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasting;

use App\Enums\FeatureEnum;
use App\Events\Scout\ZoneAssignmentsChanged;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ScoutChannelAuthTest extends TestCase
{
    private User $user;

    private Org $org;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Force the pusher broadcaster for this test class so channel auth
        // callbacks actually run. LogBroadcaster/NullBroadcaster have a no-op
        // auth() and return 200 for every request, masking real failures.
        // The Pusher SDK only does local HMAC signing during auth — no
        // network call — so fake credentials are safe for these tests.
        config()->set('broadcasting.default', 'pusher');
        config()->set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => 'test-app',
            'options' => [
                'host' => '127.0.0.1',
                'port' => 6001,
                'scheme' => 'http',
                'encrypted' => false,
                'useTLS' => false,
            ],
        ]);
        Broadcast::purge();

        // Channels are registered on the broadcaster that was the default at
        // AppServiceProvider boot time (log). Since we just swapped the
        // default to pusher and purged the cached driver, re-load
        // routes/channels.php so the patterns register on the fresh pusher
        // driver — otherwise retrieveUser/verifyUserCanAccessChannel run
        // against an empty channel registry and reject every request.
        require base_path('routes/channels.php');

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $this->org = Org::factory()->create();
        $this->makeOrgPro($this->org);

        $kit = Kit::factory()->forOrg($this->org)->create();
        $this->user = User::factory()
            ->forOrg($this->org)
            ->forKit($kit)
            ->create();

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        Feature::for($this->user)->activate(FeatureEnum::CLARITY_SCOUT->slug());

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_authorizes_own_scout_channel(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-clarity.scout.user.'.$this->user->id,
            ]);

        $response->assertSuccessful();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_denies_other_users_channel(): void
    {
        $other = User::factory()->forOrg($this->org)->create();

        $response = $this->withToken($this->token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-clarity.scout.user.'.$other->id,
            ]);

        $response->assertForbidden();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_denies_when_user_has_no_kit(): void
    {
        $kitless = User::factory()->forOrg($this->org)->create();
        setPermissionsTeamId($this->org->id);
        $kitless->givePermissionTo('clarity-scout');
        Feature::for($kitless)->activate(FeatureEnum::CLARITY_SCOUT->slug());

        $token = $kitless->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-clarity.scout.user.'.$kitless->id,
            ]);

        $response->assertForbidden();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_denies_when_user_lacks_clarity_scout_permission(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();
        $other = User::factory()->forOrg($this->org)->forKit($kit)->create();
        Feature::for($other)->activate(FeatureEnum::CLARITY_SCOUT->slug());

        $token = $other->createToken('clarity-scout-token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-clarity.scout.user.'.$other->id,
            ]);

        $response->assertForbidden();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_denies_when_unauthenticated(): void
    {
        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-clarity.scout.user.'.$this->user->id,
        ]);

        $response->assertUnauthorized();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_denies_non_numeric_user_id_in_channel_name(): void
    {
        // The frontend builds channel names as `private-clarity.scout.user.${authStore.user.id}`,
        // so the suffix is always numeric. If something else ends up there, we
        // should refuse to authorize rather than crash or pass-through.
        $response = $this->withToken($this->token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-clarity.scout.user.abc',
            ]);

        $this->assertContains($response->status(), [403, 404], 'Expected 403/404 for non-numeric channel suffix');
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_channel_name_matches_event_broadcast_target(): void
    {
        // Pins the channel name contract between PHP (PrivateChannel) and the
        // JS Echo subscriber (`echo.private('clarity.scout.user.${userId}')`).
        // If either side renames the channel, this test fails before runtime.
        $event = new ZoneAssignmentsChanged($this->user->id);
        $authorChannel = $event->broadcastOn()[0]->name;

        $this->assertSame('private-clarity.scout.user.'.$this->user->id, $authorChannel);

        // And the auth route accepts that exact name.
        $response = $this->withToken($this->token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $authorChannel,
            ]);

        $response->assertSuccessful();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_force_token_authorizes_channel_despite_missing_gates(): void
    {
        // An admin impersonation token (clarity-scout-force) authorizes the
        // target user's channel even with no kit/permission/feature — mirroring
        // the middleware bypass. Pins the documented force-login behavior.
        $bare = User::factory()->forOrg($this->org)->create();
        $token = $bare->createToken('clarity-scout-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-clarity.scout.user.'.$bare->id,
            ])
            ->assertSuccessful();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_denies_when_feature_inactive(): void
    {
        // Kit + permission present, but the org isn't Pro so the Scout feature
        // is inactive — the channel gate (channels.php:44) must reject.
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();
        $user = User::factory()->forOrg($org)->forKit($kit)->create();

        setPermissionsTeamId($org->id);
        $user->givePermissionTo('clarity-scout');

        $token = $user->createToken('clarity-scout-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-clarity.scout.user.'.$user->id,
            ])
            ->assertForbidden();
    }

    #[Group('broadcasting')]
    #[Group('clarity-scout')]
    public function test_denies_after_permission_revoked_mid_session(): void
    {
        $payload = [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-clarity.scout.user.'.$this->user->id,
        ];

        // Initially authorized.
        $this->withToken($this->token)
            ->postJson('/api/broadcasting/auth', $payload)
            ->assertSuccessful();

        // Revoking the permission must invalidate the next channel auth even
        // though the bearer token is still valid (DEV-1916 §9 / TC-RT4).
        setPermissionsTeamId($this->user->org_id);
        $this->user->revokePermissionTo('clarity-scout');

        // The auth guard caches the user it resolved in the first request for the
        // lifetime of the app instance; a real "next request" would resolve the
        // user fresh. Forget the guards so the second auth re-resolves and sees
        // the revoked permission, mirroring a separate production request.
        Auth::forgetGuards();

        $this->withToken($this->token)
            ->postJson('/api/broadcasting/auth', $payload)
            ->assertForbidden();
    }
}
