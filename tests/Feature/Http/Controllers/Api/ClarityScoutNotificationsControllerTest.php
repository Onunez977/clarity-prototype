<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\ScoutAnalyticsEvent;
use App\Facades\Analytics;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Services\Analytics\AnalyticsService;
use App\Services\Scout\ScoutNotifier;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutNotificationsControllerTest extends TestCase
{
    private User $user;

    private Org $org;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Analytics::shouldReceive('track')->zeroOrMoreTimes()->andReturnNull();

        $this->org = Org::factory()->create();
        $this->makeOrgPro($this->org);

        $kit = Kit::factory()->forOrg($this->org)->create();
        $this->user = User::factory()
            ->forOrg($this->org)
            ->forKit($kit)
            ->create(['dti' => ['timeZone' => 'UTC']]);

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_index_returns_notifications_newest_first_with_unread_count(): void
    {
        $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => 'You were added to North.', 'zone_id' => 1, 'zone_name' => 'North'],
            'read_at' => now(),
            'created_at' => now()->subMinutes(10),
        ]);
        $newest = $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_updated', 'title' => 'Zone updated', 'body' => 'South was updated.', 'zone_id' => 2, 'zone_name' => 'South'],
            'read_at' => null,
            'created_at' => now(),
        ]);

        $response = $this->withToken($this->token)->getJson('/api/scout/notifications');

        $response->assertSuccessful()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.id', $newest->id)
            ->assertJsonPath('notifications.0.body', 'South was updated.')
            ->assertJsonPath('unread_count', 1);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_mark_read_marks_a_single_notification(): void
    {
        $notification = $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => 'You were added to North.', 'zone_id' => 1, 'zone_name' => 'North'],
            'read_at' => null,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/scout/notifications/{$notification->id}/read");

        $response->assertSuccessful()->assertJsonPath('unread_count', 0);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_mark_all_read_clears_unread(): void
    {
        foreach (range(1, 3) as $i) {
            $this->user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'scout.zone',
                'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => "Added {$i}.", 'zone_id' => $i, 'zone_name' => "Zone {$i}"],
                'read_at' => null,
            ]);
        }

        $response = $this->withToken($this->token)->postJson('/api/scout/notifications/read-all');

        $response->assertSuccessful()->assertJsonPath('unread_count', 0);
        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_mark_read_tracks_analytics(): void
    {
        // setUp() mocks the Analytics facade, so force a fresh spy in its place
        // (Facade::spy() no-ops once the facade is already a mock).
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        $notification = $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => 'Added.', 'zone_id' => 1, 'zone_name' => 'North'],
            'read_at' => null,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/scout/notifications/{$notification->id}/read")
            ->assertSuccessful();

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::NotificationRead->value)
            ->once();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_mark_another_users_notification_read(): void
    {
        $other = User::factory()->forOrg($this->org)->create();
        $notification = $other->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => 'Not yours.', 'zone_id' => 1, 'zone_name' => 'North'],
            'read_at' => null,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/scout/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_index_excludes_filament_notifications_and_counts_only_scout_unread(): void
    {
        $scout = $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => 'You were added to North.', 'zone_id' => 1, 'zone_name' => 'North'],
            'read_at' => null,
        ]);
        $this->makeFilamentNotification();

        $this->withToken($this->token)->getJson('/api/scout/notifications')
            ->assertSuccessful()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $scout->id)
            ->assertJsonPath('unread_count', 1);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_mark_all_read_leaves_filament_notifications_untouched(): void
    {
        $scout = $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => 'You were added to North.', 'zone_id' => 1, 'zone_name' => 'North'],
            'read_at' => null,
        ]);
        $filament = $this->makeFilamentNotification();

        $this->withToken($this->token)->postJson('/api/scout/notifications/read-all')
            ->assertSuccessful()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($scout->fresh()->read_at);
        $this->assertNull($filament->fresh()->read_at);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_cannot_mark_a_filament_notification_read(): void
    {
        $filament = $this->makeFilamentNotification();

        $this->withToken($this->token)
            ->postJson("/api/scout/notifications/{$filament->id}/read")
            ->assertNotFound();

        $this->assertNull($filament->fresh()->read_at);
    }

    #[Group('services')]
    #[Group('clarity-scout')]
    public function test_scout_notifier_persists_a_notification_row(): void
    {
        $zone = Zone::create([
            'org_id' => $this->org->id,
            'name' => 'North',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);

        $notification = app(ScoutNotifier::class)->zoneAdded($this->user->id, $zone);

        $this->assertSame('zone_added', $notification->data['kind']);
        $this->assertSame('You were added to North.', $notification->data['body']);
        $this->assertSame($zone->id, $notification->data['zone_id']);
        $this->assertSame(1, $this->user->unreadNotifications()->count());
    }

    /**
     * Contract: lock the notification payload + envelope the PWA bell reads, so a
     * shape change fails CI rather than silently breaking the (untested) PWA.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_notification_response_shapes_are_locked(): void
    {
        $notification = $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            'data' => ['kind' => 'zone_added', 'title' => 'Zone assigned', 'body' => 'Added.', 'zone_id' => 1, 'zone_name' => 'North'],
            'read_at' => null,
        ]);

        $itemKeys = ['id', 'kind', 'title', 'body', 'zone_id', 'zone_name', 'read_at', 'created_at'];

        $this->withToken($this->token)->getJson('/api/scout/notifications')
            ->assertSuccessful()
            ->assertJsonStructure([
                'notifications' => [$itemKeys],
                'unread_count',
            ]);

        $this->withToken($this->token)->postJson("/api/scout/notifications/{$notification->id}/read")
            ->assertSuccessful()
            ->assertJsonStructure([
                'notification' => $itemKeys,
                'unread_count',
            ]);

        $this->withToken($this->token)->postJson('/api/scout/notifications/read-all')
            ->assertSuccessful()
            ->assertJsonStructure(['unread_count']);
    }

    /**
     * Seed an admin-panel Filament notification on the same user, so tests can
     * prove these never leak into the Scout bell.
     */
    private function makeFilamentNotification(?Carbon $readAt = null): DatabaseNotification
    {
        return $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'Filament\Notifications\DatabaseNotification',
            'data' => ['title' => '1 hit(s) resent to ClearPlan', 'body' => null],
            'read_at' => $readAt,
        ]);
    }
}
