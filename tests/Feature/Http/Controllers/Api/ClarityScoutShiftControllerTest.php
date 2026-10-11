<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ShiftEndTypeEnum;
use App\Facades\Analytics;
use App\Jobs\Scout\SendEndOfShiftSummary;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Org;
use App\Models\Scan;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClarityScoutShiftControllerTest extends TestCase
{
    private User $user;

    private Kit $kit;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Analytics::shouldReceive('track')->zeroOrMoreTimes()->andReturnNull();

        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $this->kit = Kit::factory()->forOrg($org)->create();

        $this->user = User::factory()->forOrg($org)->forKit($this->kit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    private function asScout(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }

    #[Test]
    public function status_returns_no_shift_when_no_recent_activity(): void
    {
        $this->assertDatabaseCount('kit_shifts', 0);

        $response = $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status');

        $response->assertOk()
            ->assertJsonPath('data.open', false)
            ->assertJsonPath('data.shift_id', null);

        $this->assertDatabaseCount('kit_shifts', 0);
    }

    #[Test]
    public function status_opens_a_new_shift_when_recent_activity_exists(): void
    {
        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => Carbon::now()->subMinutes(15),
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        $response = $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status');

        $response->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.ended_at', null);

        $this->assertDatabaseHas('kit_shifts', [
            'kit_id' => $this->kit->id,
            'ended_at' => null,
        ]);
    }

    #[Test]
    public function status_returns_same_shift_on_repeated_calls_with_activity(): void
    {
        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => Carbon::now()->subMinutes(15),
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        $a = $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status')->json('data.shift_id');
        $b = $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status')->json('data.shift_id');

        $this->assertSame($a, $b);
        $this->assertDatabaseCount('kit_shifts', 1);
    }

    #[Test]
    public function end_closes_the_shift_as_confirmed_and_dispatches_the_summary_job(): void
    {
        Queue::fake();

        $response = $this->withHeaders($this->asScout())->postJson('/api/scout/shift/end');

        $response->assertOk()
            ->assertJsonPath('data.open', false)
            ->assertJsonPath('data.end_type', ShiftEndTypeEnum::Confirmed->value)
            ->assertJsonPath('data.newly_closed', true);

        $shift = KitShift::query()->where('kit_id', $this->kit->id)->first();
        $this->assertNotNull($shift);
        $this->assertSame(ShiftEndTypeEnum::Confirmed, $shift->end_type);
        $this->assertNotNull($shift->ended_at);

        Queue::assertPushed(SendEndOfShiftSummary::class, fn ($job) => $job->shiftId === $shift->id);
    }

    #[Test]
    public function end_is_idempotent_and_does_not_re_dispatch_the_summary_job(): void
    {
        Queue::fake();

        $first = $this->withHeaders($this->asScout())->postJson('/api/scout/shift/end');
        $second = $this->withHeaders($this->asScout())->postJson('/api/scout/shift/end');

        $first->assertOk()->assertJsonPath('data.newly_closed', true);
        $second->assertOk()->assertJsonPath('data.newly_closed', false);

        // The second POST must NOT open + close a fresh kit_shifts row just
        // because the first call already closed the original one. (Caught in
        // QA against http://qa.platform.test on 2026-05-26 — would have
        // shipped silently broken otherwise.)
        $this->assertSame(1, KitShift::query()->where('kit_id', $this->kit->id)->count());
        $this->assertSame(
            $first->json('data.shift_id'),
            $second->json('data.shift_id'),
            'Both POSTs must surface the same shift_id',
        );

        Queue::assertPushed(SendEndOfShiftSummary::class, 1);
    }

    #[Test]
    public function status_after_end_returns_no_open_shift_until_new_activity(): void
    {
        Queue::fake();

        // End the current shift.
        $this->withHeaders($this->asScout())->postJson('/api/scout/shift/end')->assertOk();

        // No new activity yet — status should report no open shift.
        $response = $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status');

        $response->assertOk()
            ->assertJsonPath('data.open', false)
            ->assertJsonPath('data.shift_id', null);
    }

    #[Test]
    public function status_after_end_opens_a_new_shift_when_new_activity_arrives(): void
    {
        Queue::fake();

        // End the current shift.
        $this->withHeaders($this->asScout())->postJson('/api/scout/shift/end')->assertOk();

        // New scan arrives AFTER the ended shift's ended_at.
        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => Carbon::now()->addSeconds(2),
            'created_at' => Carbon::now()->addSeconds(2),
        ]);

        $response = $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status');

        $response->assertOk()
            ->assertJsonPath('data.open', true);

        $this->assertDatabaseCount('kit_shifts', 2);
    }

    #[Test]
    public function opening_a_shift_via_status_tracks_shift_start_and_writes_an_audit_row(): void
    {
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => Carbon::now()->subMinutes(15),
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status')->assertOk();

        $shift = KitShift::query()->where('kit_id', $this->kit->id)->firstOrFail();

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::ShiftStart->value)
            ->once();

        $activity = Activity::query()
            ->where('log_name', 'scout-shift')
            ->where('subject_id', $shift->id)
            ->where('description', 'Scout shift started')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('start', $activity->properties['action'] ?? null);
        $this->assertSame($this->user->id, $activity->causer_id);
    }

    #[Test]
    public function status_does_not_open_a_shift_or_track_shift_start_for_a_force_login_impersonator(): void
    {
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => Carbon::now()->subMinutes(15),
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        $forceToken = $this->user->createToken('clarity-scout-force-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$forceToken,
            'Accept' => 'application/json',
        ])->getJson('/api/scout/shift/status');

        $response->assertOk()
            ->assertJsonPath('data.open', false)
            ->assertJsonPath('data.shift_id', null);

        $this->assertDatabaseCount('kit_shifts', 0);

        $spy->shouldNotHaveReceived('track');

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'scout-shift')->where('description', 'Scout shift started')->count(),
        );
    }

    #[Test]
    public function end_does_not_close_a_shift_or_track_shift_end_for_a_force_login_impersonator(): void
    {
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        Queue::fake();

        $forceToken = $this->user->createToken('clarity-scout-force-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$forceToken,
            'Accept' => 'application/json',
        ])->postJson('/api/scout/shift/end');

        $response->assertOk()
            ->assertJsonPath('data.open', false)
            ->assertJsonPath('data.shift_id', null)
            ->assertJsonStructure([
                'data' => ['shift_id', 'open', 'started_at', 'ended_at', 'end_type', 'newly_closed'],
            ]);

        $this->assertDatabaseCount('kit_shifts', 0);

        $spy->shouldNotHaveReceived('track');

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'scout-shift')->where('description', 'Scout shift ended')->count(),
        );

        Queue::assertNotPushed(SendEndOfShiftSummary::class);
    }

    #[Test]
    public function end_leaves_a_genuinely_open_shift_untouched_for_a_force_login_impersonator(): void
    {
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        Queue::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create();

        $forceToken = $this->user->createToken('clarity-scout-force-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$forceToken,
            'Accept' => 'application/json',
        ])->postJson('/api/scout/shift/end');

        $response->assertOk()
            ->assertJsonPath('data.open', false)
            ->assertJsonPath('data.shift_id', null);

        $shift->refresh();
        $this->assertNull($shift->ended_at);
        $this->assertNull($shift->end_type);

        $spy->shouldNotHaveReceived('track');

        Queue::assertNotPushed(SendEndOfShiftSummary::class);
    }

    #[Test]
    public function repeated_status_calls_only_track_shift_start_once(): void
    {
        $spy = \Mockery::spy(AnalyticsService::class);
        Analytics::swap($spy);

        Scan::factory()->create([
            'kit_id' => $this->kit->id,
            'scanned_at' => Carbon::now()->subMinutes(15),
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status')->assertOk();
        $this->withHeaders($this->asScout())->getJson('/api/scout/shift/status')->assertOk();

        $spy->shouldHaveReceived('track')
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === ScoutAnalyticsEvent::ShiftStart->value)
            ->once();

        $this->assertSame(1, Activity::query()->where('log_name', 'scout-shift')->where('description', 'Scout shift started')->count());
    }

    #[Test]
    public function ending_a_shift_writes_an_audit_row(): void
    {
        Queue::fake();

        $this->withHeaders($this->asScout())->postJson('/api/scout/shift/end')->assertOk();

        $shift = KitShift::query()->where('kit_id', $this->kit->id)->firstOrFail();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'scout-shift',
            'subject_id' => $shift->id,
            'description' => 'Scout shift ended',
        ]);
    }

    #[Test]
    public function unauthenticated_users_receive_401_on_status(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/scout/shift/status')
            ->assertStatus(401);
    }

    #[Test]
    public function unauthenticated_users_receive_401_on_end(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/scout/shift/end')
            ->assertStatus(401);
    }

    /**
     * Contract: the PWA reads these exact keys off shift/status and shift/end.
     * Locks the response shape so a controller change that renames/removes a
     * field fails CI rather than silently breaking the (untested) PWA.
     */
    #[Test]
    public function status_and_end_response_shapes_are_locked(): void
    {
        Queue::fake();

        $this->withHeaders($this->asScout())
            ->getJson('/api/scout/shift/status')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['shift_id', 'open', 'started_at', 'ended_at', 'end_type'],
            ]);

        $this->withHeaders($this->asScout())
            ->postJson('/api/scout/shift/end')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['shift_id', 'open', 'started_at', 'ended_at', 'end_type', 'newly_closed'],
            ]);
    }
}
