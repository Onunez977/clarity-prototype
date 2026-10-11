<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Jobs\TrackAnalytics;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutAnalyticsControllerTest extends TestCase
{
    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Analytics::shouldReceive('track')->zeroOrMoreTimes()->andReturnNull();

        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();
        $this->user = User::factory()->forOrg($org)->forKit($kit)->create();

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    #[Test]
    #[Group('clarity-scout')]
    public function a_registered_event_is_tracked(): void
    {
        // setUp() already mocks the Analytics facade, so Facade::spy() would no-op
        // (isMock() === true) and return null — force a fresh spy in its place.
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        $this->withToken($this->token)->postJson('/api/scout/track', [
            'feature' => ScoutAnalyticsEvent::PanelToggle->value,
            'page' => ScoutAnalyticsPage::App->value,
            'params' => ['action' => 'open', 'panel' => 'help'],
        ])->assertNoContent();

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::PanelToggle->value
                && ($args[3] ?? null) === ScoutAnalyticsPage::App->value)
            ->once();
    }

    #[Test]
    #[Group('clarity-scout')]
    public function a_force_login_impersonator_does_not_have_their_track_call_queued(): void
    {
        // setUp()'s permissive Analytics::shouldReceive() hides the real class
        // entirely, and a Mockery spy never calls through to real code either —
        // swap in a genuine instance so AnalyticsService::track()'s guard runs
        // for real and its effect (no job dispatched) is observable.
        Analytics::swap(new AnalyticsService);
        config()->set('database.track_analytics', true);
        config()->set('database.use_clickhouse', true);
        Queue::fake();

        $forceToken = $this->user->createToken('clarity-scout-force-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $this->withToken($forceToken)->postJson('/api/scout/track', [
            'feature' => ScoutAnalyticsEvent::PanelToggle->value,
            'page' => ScoutAnalyticsPage::App->value,
            'params' => ['action' => 'open', 'panel' => 'help'],
        ])->assertNoContent();

        Queue::assertNotPushed(TrackAnalytics::class);
    }

    #[Test]
    #[Group('clarity-scout')]
    public function a_normal_driver_has_their_track_call_queued(): void
    {
        Analytics::swap(new AnalyticsService);
        config()->set('database.track_analytics', true);
        config()->set('database.use_clickhouse', true);
        Queue::fake();

        $this->withToken($this->token)->postJson('/api/scout/track', [
            'feature' => ScoutAnalyticsEvent::PanelToggle->value,
            'page' => ScoutAnalyticsPage::App->value,
            'params' => ['action' => 'open', 'panel' => 'help'],
        ])->assertNoContent();

        Queue::assertPushed(TrackAnalytics::class);
    }

    #[Test]
    #[Group('clarity-scout')]
    public function an_unregistered_feature_name_is_rejected(): void
    {
        $this->withToken($this->token)->postJson('/api/scout/track', [
            'feature' => 'totally-made-up-event',
            'page' => ScoutAnalyticsPage::App->value,
        ])->assertStatus(422)->assertJsonValidationErrors('feature');
    }

    #[Test]
    #[Group('clarity-scout')]
    public function an_unregistered_page_tag_is_rejected(): void
    {
        $this->withToken($this->token)->postJson('/api/scout/track', [
            'feature' => ScoutAnalyticsEvent::PanelToggle->value,
            'page' => 'not-a-scout-page',
        ])->assertStatus(422)->assertJsonValidationErrors('page');
    }

    #[Test]
    #[Group('clarity-scout')]
    public function a_small_params_map_passes(): void
    {
        $this->withToken($this->token)->postJson('/api/scout/track', [
            'feature' => ScoutAnalyticsEvent::PanelToggle->value,
            'page' => ScoutAnalyticsPage::App->value,
            'params' => ['action' => 'open', 'panel' => 'help'],
        ])->assertNoContent();
    }

    #[Test]
    #[Group('clarity-scout')]
    public function an_oversized_params_map_is_rejected(): void
    {
        $params = [];

        for ($i = 0; $i < 21; $i++) {
            $params['key'.$i] = 'v';
        }

        $this->withToken($this->token)->postJson('/api/scout/track', [
            'feature' => ScoutAnalyticsEvent::PanelToggle->value,
            'page' => ScoutAnalyticsPage::App->value,
            'params' => $params,
        ])->assertStatus(422)->assertJsonValidationErrors('params');
    }

    #[Test]
    #[Group('clarity-scout')]
    public function an_overlong_param_value_is_rejected(): void
    {
        $this->withToken($this->token)->postJson('/api/scout/track', [
            'feature' => ScoutAnalyticsEvent::PanelToggle->value,
            'page' => ScoutAnalyticsPage::App->value,
            'params' => ['note' => str_repeat('x', 501)],
        ])->assertStatus(422)->assertJsonValidationErrors('params.note');
    }

    #[Test]
    #[Group('clarity-scout')]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/scout/track', ['feature' => ScoutAnalyticsEvent::PanelToggle->value])
            ->assertStatus(401);
    }
}
