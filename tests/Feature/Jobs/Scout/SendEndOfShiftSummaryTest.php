<?php

namespace Tests\Feature\Jobs\Scout;

use App\Dto\Scout\ShiftSummaryDto;
use App\Enums\ShiftEndTypeEnum;
use App\Jobs\Scout\SendEndOfShiftSummary;
use App\Mail\Scout\EndOfShiftSummary;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Org;
use App\Models\User;
use App\Services\Scout\BuildShiftSummary;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SendEndOfShiftSummaryTest extends TestCase
{
    private Kit $kit;

    private Org $org;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $this->org = Org::factory()->create();
        $this->kit = Kit::factory()->forOrg($this->org)->create();
        $this->driver = User::factory()->forOrg($this->org)->forKit($this->kit)->create([
            'email' => 'driver@example.com',
            'name' => 'Driver McDriverface',
        ]);

        setPermissionsTeamId($this->org->id);
        Role::findOrCreate(RoleEnum::ADMIN->value, 'web');
    }

    private function fakeSummary(KitShift $shift, ?string $driverEmail = 'driver@example.com', array $overrides = []): ShiftSummaryDto
    {
        return new ShiftSummaryDto(
            shiftId: $shift->id,
            kitId: $shift->kit_id,
            kitName: 'Test Kit',
            driverName: 'Driver McDriverface',
            driverEmail: $driverEmail,
            startedAt: $shift->started_at,
            endedAt: $shift->ended_at,
            windowStartedAt: $overrides['windowStartedAt'] ?? $shift->started_at,
            windowEndedAt: $overrides['windowEndedAt'] ?? $shift->ended_at,
            endType: $shift->end_type ?? ShiftEndTypeEnum::Confirmed,
            totalMiles: $overrides['miles'] ?? 12.4,
            totalScans: $overrides['scans'] ?? 100,
            totalHits: $overrides['hits'] ?? 2,
            zones: $overrides['zones'] ?? [],
            mapUrl: $overrides['mapUrl'] ?? null,
        );
    }

    private function makeAdmin(string $email): User
    {
        $admin = User::factory()->forOrg($this->org)->create(['email' => $email]);
        setPermissionsTeamId($this->org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        return $admin;
    }

    #[Test]
    public function driver_email_present_sends_to_driver_and_ccs_admins(): void
    {
        Mail::fake();

        $this->makeAdmin('admin1@example.com');
        $this->makeAdmin('admin2@example.com');

        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();

        $this->mock(BuildShiftSummary::class, function ($mock) use ($shift) {
            $mock->shouldReceive('forShift')->andReturn($this->fakeSummary($shift));
        });

        (new SendEndOfShiftSummary($shift->id))->handle(app(BuildShiftSummary::class));

        Mail::assertQueued(EndOfShiftSummary::class, function ($mail) {
            return $mail->hasTo('driver@example.com')
                && $mail->hasCc('admin1@example.com')
                && $mail->hasCc('admin2@example.com');
        });

        $this->assertNotNull($shift->fresh()->summary_sent_at);
    }

    #[Test]
    public function driver_email_absent_sends_to_admins_only(): void
    {
        Mail::fake();

        $this->makeAdmin('admin1@example.com');
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();

        $this->mock(BuildShiftSummary::class, function ($mock) use ($shift) {
            $mock->shouldReceive('forShift')->andReturn($this->fakeSummary($shift, driverEmail: null));
        });

        (new SendEndOfShiftSummary($shift->id))->handle(app(BuildShiftSummary::class));

        Mail::assertQueued(EndOfShiftSummary::class, function ($mail) {
            return $mail->hasTo('admin1@example.com')
                && $mail->driverEmailMissing === true;
        });
    }

    #[Test]
    public function no_recipients_records_skipped_reason_and_skips_send(): void
    {
        Mail::fake();

        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();

        $this->mock(BuildShiftSummary::class, function ($mock) use ($shift) {
            $mock->shouldReceive('forShift')->andReturn($this->fakeSummary($shift, driverEmail: null));
        });

        // No admins created.
        (new SendEndOfShiftSummary($shift->id))->handle(app(BuildShiftSummary::class));

        Mail::assertNothingQueued();
        $this->assertSame('no_recipients', $shift->fresh()->summary_skipped_reason);
        $this->assertNull($shift->fresh()->summary_sent_at);
    }

    #[Test]
    public function job_is_idempotent_when_summary_already_sent(): void
    {
        Mail::fake();

        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->summarySent()->create();

        // Builder should never be called.
        $builder = Mockery::mock(BuildShiftSummary::class);
        $builder->shouldNotReceive('forShift');

        (new SendEndOfShiftSummary($shift->id))->handle($builder);

        Mail::assertNothingQueued();
    }

    #[Test]
    public function job_refuses_to_send_when_shift_is_still_open(): void
    {
        Mail::fake();

        $shift = KitShift::factory()->forKit($this->kit)->open()->create();

        $builder = Mockery::mock(BuildShiftSummary::class);
        $builder->shouldNotReceive('forShift');

        (new SendEndOfShiftSummary($shift->id))->handle($builder);

        Mail::assertNothingQueued();
        $this->assertNull($shift->fresh()->summary_sent_at);
    }

    #[Test]
    public function terminal_mail_transport_failure_records_mail_failed_skip_reason(): void
    {
        // Only a genuine mail-transport failure is labelled mail_failed.
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();

        (new SendEndOfShiftSummary($shift->id))->failed(new TransportException('SMTP down'));

        $this->assertSame('mail_failed', $shift->fresh()->summary_skipped_reason);
    }

    #[Test]
    public function terminal_query_exception_records_build_failed_skip_reason(): void
    {
        // DEV-2325: a build/query-timeout failure (e.g. the 57014 statement
        // timeout the zone-breakdown query used to hit) must be distinguished
        // from a genuine mail-send failure, so support can tell them apart.
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();

        $queryException = new QueryException(
            'platform',
            'select * from scans',
            [],
            new \PDOException('SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout')
        );

        (new SendEndOfShiftSummary($shift->id))->failed($queryException);

        $this->assertSame('build_failed', $shift->fresh()->summary_skipped_reason);
    }

    #[Test]
    public function terminal_non_mail_build_failure_records_build_failed_skip_reason(): void
    {
        // DEV-2325 (review): the summary build reads ClickHouse (miles / scan /
        // hit counts) BEFORE Mail::send(), and ClickHouse failures surface as
        // non-QueryException throwables. They must still be labelled build_failed
        // — a CH outage is a build failure, not a mail failure.
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();

        (new SendEndOfShiftSummary($shift->id))->failed(
            new \RuntimeException('ClickHouse read timed out while building the summary')
        );

        $this->assertSame('build_failed', $shift->fresh()->summary_skipped_reason);
    }

    #[Test]
    public function terminal_failure_leaves_an_already_sent_shift_untouched(): void
    {
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->summarySent()->create();

        (new SendEndOfShiftSummary($shift->id))->failed(new \RuntimeException('SMTP down'));

        $this->assertNull($shift->fresh()->summary_skipped_reason);
        $this->assertNotNull($shift->fresh()->summary_sent_at);
    }

    #[Test]
    public function truncated_empty_window_logs_a_warning_and_still_sends(): void
    {
        Mail::fake();
        Event::fake([MessageLogged::class]);

        $endedAt = Carbon::parse('2026-08-18 08:10:00', 'UTC');
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create([
            'started_at' => $endedAt->copy()->subDays(17),
            'ended_at' => $endedAt,
        ]);
        $windowStart = $endedAt->copy()->subHours(BuildShiftSummary::SUMMARY_WINDOW_HOURS);

        $this->mock(BuildShiftSummary::class, function ($mock) use ($shift, $windowStart, $endedAt) {
            $mock->shouldReceive('forShift')->andReturn($this->fakeSummary($shift, overrides: [
                'miles' => 0.0,
                'scans' => 0,
                'hits' => 0,
                'windowStartedAt' => $windowStart,
                'windowEndedAt' => $endedAt,
            ]));
        });

        (new SendEndOfShiftSummary($shift->id))->handle(app(BuildShiftSummary::class));

        Mail::assertQueued(EndOfShiftSummary::class);
        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event) use ($shift, $windowStart, $endedAt): bool {
            return $event->level === 'warning'
                && $event->message === 'SendEndOfShiftSummary: truncated window has no activity'
                && $event->context['shift_id'] === $shift->id
                && $event->context['kit_id'] === $shift->kit_id
                && $event->context['window_started_at'] === $windowStart->toDateTimeString()
                && $event->context['window_ended_at'] === $endedAt->toDateTimeString()
                && $event->context['window_hours'] === BuildShiftSummary::SUMMARY_WINDOW_HOURS;
        });
        $this->assertNotNull($shift->fresh()->summary_sent_at);
        $this->assertNull($shift->fresh()->summary_skipped_reason);
    }

    #[Test]
    public function truncated_window_with_activity_does_not_log_the_empty_window_warning(): void
    {
        Mail::fake();
        Event::fake([MessageLogged::class]);

        $endedAt = Carbon::parse('2026-08-18 08:10:00', 'UTC');
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create([
            'started_at' => $endedAt->copy()->subDays(17),
            'ended_at' => $endedAt,
        ]);

        $this->mock(BuildShiftSummary::class, function ($mock) use ($shift, $endedAt) {
            $mock->shouldReceive('forShift')->andReturn($this->fakeSummary($shift, overrides: [
                'windowStartedAt' => $endedAt->copy()->subHours(BuildShiftSummary::SUMMARY_WINDOW_HOURS),
                'windowEndedAt' => $endedAt,
            ]));
        });

        (new SendEndOfShiftSummary($shift->id))->handle(app(BuildShiftSummary::class));

        Mail::assertQueued(EndOfShiftSummary::class);
        Event::assertNotDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->message === 'SendEndOfShiftSummary: truncated window has no activity';
        });
    }

    #[Test]
    public function short_empty_shift_does_not_log_the_truncated_window_warning(): void
    {
        Mail::fake();
        Event::fake([MessageLogged::class]);

        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();

        $this->mock(BuildShiftSummary::class, function ($mock) use ($shift) {
            $mock->shouldReceive('forShift')->andReturn($this->fakeSummary($shift, overrides: [
                'miles' => 0.0,
                'scans' => 0,
                'hits' => 0,
            ]));
        });

        (new SendEndOfShiftSummary($shift->id))->handle(app(BuildShiftSummary::class));

        Mail::assertQueued(EndOfShiftSummary::class);
        Event::assertNotDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->message === 'SendEndOfShiftSummary: truncated window has no activity';
        });
        $this->assertNotNull($shift->fresh()->summary_sent_at);
    }

    #[Test]
    public function it_attempts_the_job_at_most_twice_with_backoff(): void
    {
        $shift = KitShift::factory()->forKit($this->kit)->confirmed()->create();
        $job = new SendEndOfShiftSummary($shift->id);

        $this->assertSame(2, $job->tries);
        $this->assertSame(30, $job->backoff);
    }
}
