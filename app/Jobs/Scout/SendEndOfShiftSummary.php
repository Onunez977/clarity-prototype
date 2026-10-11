<?php

declare(strict_types=1);

namespace App\Jobs\Scout;

use App\Console\Commands\CloseInactiveShiftsCommand;
use App\Http\Controllers\Api\ClarityScoutShiftController;
use App\Mail\Scout\EndOfShiftSummary as EndOfShiftSummaryMail;
use App\Models\KitShift;
use App\Models\User;
use App\Services\Scout\BuildShiftSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Build a shift summary and email it to the driver (To:) + company admins (CC:).
 *
 * Dispatched from two places:
 *   - {@see ClarityScoutShiftController::end()}     — confirmed end
 *   - {@see CloseInactiveShiftsCommand}             — assumed end
 *
 * Idempotency is enforced via a `lockForUpdate` on the `kit_shifts` row that
 * spans the `summary_sent_at` read, the mail send, and the final stamp.
 * Concurrent workers picking up duplicate dispatches will serialize on the
 * row lock; the second one finds `summary_sent_at` already set and bails.
 *
 * Recipient rules (per DEV-1788 reporter comment):
 *   - Driver email present → To: driver, CC: admins
 *   - Driver email absent  → To: admins (summary banner flags missing contact)
 *   - No recipients at all → record `summary_skipped_reason = no_recipients`
 *   - Truncated last-N window with no scans/hits/miles → still send the empty
 *     last-N email; {@see Log::warning} so support can see overnight confirmed-ends
 *     (DEV-2385). This is not a skip — `summary_skipped_reason` stays unset.
 *
 * On terminal failure (all retries exhausted; {@see self::$tries} is 2), {@see self::failed()}
 * records a `summary_skipped_reason` so support has a paper trail and the shift
 * doesn't sit in an ambiguous "neither sent nor skipped" state:
 *   - a mail-transport failure (`TransportExceptionInterface`) → `mail_failed`
 *   - anything else → `build_failed` — i.e. any failure while *building* the
 *     summary, whether it's the Postgres zone query timing out (the DEV-2325
 *     SQLSTATE 57014) or a ClickHouse read (miles / scan / hit counts) failing.
 *     Building reads ClickHouse before it ever reaches `Mail::send()`, so a CH
 *     outage must NOT be mislabelled as a mail failure.
 */
final class SendEndOfShiftSummary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public int $shiftId,
    ) {
        $this->onQueue('{low}');
    }

    public function handle(BuildShiftSummary $builder): void
    {
        DB::connection('platform')->transaction(function () use ($builder): void {
            $shift = KitShift::query()
                ->whereKey($this->shiftId)
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                Log::warning('SendEndOfShiftSummary: shift not found', ['shift_id' => $this->shiftId]);

                return;
            }

            if ($shift->summary_sent_at !== null) {
                // Already sent — defensive no-op for retries / double dispatches.
                return;
            }

            if ($shift->ended_at === null) {
                Log::warning('SendEndOfShiftSummary: shift is still open, refusing to send', [
                    'shift_id' => $shift->id,
                ]);

                return;
            }

            $summary = $builder->forShift($shift);

            if ($summary->isTruncated() && ! $summary->hasActivity()) {
                Log::warning('SendEndOfShiftSummary: truncated window has no activity', [
                    'shift_id' => $shift->id,
                    'kit_id' => $shift->kit_id,
                    'started_at' => $summary->startedAt->toDateTimeString(),
                    'ended_at' => $summary->endedAt->toDateTimeString(),
                    'window_started_at' => $summary->windowStartedAt->toDateTimeString(),
                    'window_ended_at' => $summary->windowEndedAt->toDateTimeString(),
                    'window_hours' => BuildShiftSummary::SUMMARY_WINDOW_HOURS,
                ]);
            }

            $adminEmails = $this->adminEmails($shift->org_id);

            $driverEmail = $summary->driverEmail;
            $driverEmailMissing = $driverEmail === null;

            if ($driverEmail === null && empty($adminEmails)) {
                // No one to send to — record and bail.
                $shift->forceFill([
                    'summary_skipped_reason' => 'no_recipients',
                ])->save();

                return;
            }

            $mailable = new EndOfShiftSummaryMail($summary, $driverEmailMissing);

            if ($driverEmail !== null) {
                $pending = Mail::to($driverEmail);
                if (! empty($adminEmails)) {
                    $pending->cc($adminEmails);
                }
                $pending->send($mailable);
            } else {
                Mail::to($adminEmails)->send($mailable);
            }

            $shift->forceFill([
                'summary_sent_at' => now(),
            ])->save();
        });
    }

    /**
     * Terminal-failure hook — fires after Laravel exhausts `$tries` retries.
     * We record a skip reason so the shift has a paper trail instead of
     * looking like a successful no-op. Only a genuine mail-transport failure is
     * labelled `mail_failed`; every other terminal failure is `build_failed`,
     * because the summary build (the Postgres zone query AND the ClickHouse
     * miles/scan/hit reads) all runs before `Mail::send()` — so a query timeout
     * or a ClickHouse outage is a build failure, not a mail failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SendEndOfShiftSummary: retries exhausted', [
            'shift_id' => $this->shiftId,
            'exception' => $exception?->getMessage(),
        ]);

        $shift = KitShift::query()->find($this->shiftId);

        if ($shift === null || $shift->summary_sent_at !== null) {
            // Either gone, or already sent before the final failure — leave it alone.
            return;
        }

        $shift->forceFill([
            'summary_skipped_reason' => $exception instanceof TransportExceptionInterface ? 'mail_failed' : 'build_failed',
        ])->save();
    }

    /**
     * Look up the org's admin emails for the CC list. Saves and restores the
     * Spatie Permission team-id context so a long-lived queue worker doesn't
     * inherit `$shift->org_id` as its default team for downstream operations.
     *
     * @return array<int, string>
     */
    private function adminEmails(int $orgId): array
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($orgId);

        try {
            return User::query()
                ->where('org_id', $orgId)
                ->role(RoleEnum::ADMIN->value)
                ->whereNotNull('email')
                ->pluck('email')
                ->filter()
                ->unique()
                ->values()
                ->all();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
