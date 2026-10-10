<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Clickhouse\Models\Scan as ClickhouseScan;
use App\Enums\ShiftEndTypeEnum;
use App\Jobs\Scout\SendEndOfShiftSummary;
use App\Models\Kit;
use App\Models\KitShift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find open `kit_shifts` rows whose most recent scan / GPS activity was ≥ 3
 * hours ago and close them as `assumed` shift ends. For each closed shift,
 * dispatch {@see SendEndOfShiftSummary}.
 *
 * Scheduled every 30 minutes via routes/console.php. Gated behind the
 * `app.experimental.clarity_scout` flag so it's a no-op until the portal
 * itself ships.
 */
class CloseInactiveShiftsCommand extends Command
{
    protected $signature = 'scout:close-inactive-shifts {--dry-run : Show what would close without actually closing}';

    protected $description = 'Close inactive Clarity Scout shifts (≥3h of GPS+scan inactivity) and dispatch end-of-shift summary emails.';

    private const INACTIVITY_THRESHOLD_MINUTES = 180;

    public function handle(): int
    {
        if (! config('app.experimental.clarity_scout', false)) {
            $this->info('Clarity Scout is disabled — nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $startedAt = microtime(true);
        $closedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        $openShifts = KitShift::query()->whereNull('ended_at')->get();

        if ($openShifts->isEmpty()) {
            $this->info('No open shifts to evaluate.');

            return self::SUCCESS;
        }

        foreach ($openShifts as $shift) {
            try {
                $latestActivity = $this->latestActivityFor($shift->kit_id, $shift->org_id, $shift->started_at);

                // If we have activity, decide based on its recency. If we have no
                // activity, decide based on how long the shift has been open.
                $referenceTime = $latestActivity ?? $shift->started_at;
                $minutesSince = $referenceTime->diffInMinutes($now);

                if ($minutesSince < self::INACTIVITY_THRESHOLD_MINUTES) {
                    $skippedCount++;

                    continue;
                }

                $endedAt = ($latestActivity ?? $shift->started_at)->copy();

                if ($dryRun) {
                    $this->line(sprintf(
                        'Would close kit %d shift %d (started_at=%s, ended_at=%s, end_type=assumed)',
                        $shift->kit_id,
                        $shift->id,
                        $shift->started_at->toIso8601String(),
                        $endedAt->toIso8601String(),
                    ));
                    $closedCount++;

                    continue;
                }

                DB::connection('platform')->transaction(function () use ($shift, $endedAt) {
                    $shift->forceFill([
                        'ended_at' => $endedAt,
                        'end_type' => ShiftEndTypeEnum::Assumed,
                    ])->save();
                });

                $closedCount++;

                try {
                    SendEndOfShiftSummary::dispatch($shift->id);
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('scout:close-inactive-shifts: summary dispatch failed', [
                        'shift_id' => $shift->id,
                        'kit_id' => $shift->kit_id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->warn(sprintf(
                        'Closed kit %d shift %d but summary dispatch failed: %s',
                        $shift->kit_id,
                        $shift->id,
                        $e->getMessage(),
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
                Log::warning('scout:close-inactive-shifts: shift failed', [
                    'shift_id' => $shift->id,
                    'kit_id' => $shift->kit_id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn(sprintf(
                    'Skipped kit %d shift %d: %s',
                    $shift->kit_id,
                    $shift->id,
                    $e->getMessage(),
                ));
                $failedCount++;
            }
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('scout:close-inactive-shifts: completed', [
            'closed' => $closedCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
            'duration_ms' => $durationMs,
        ]);

        $this->info(sprintf(
            'Closed %d shift(s); skipped %d shift(s) still within the inactivity window; failed %d shift(s).%s',
            $closedCount,
            $skippedCount,
            $failedCount,
            $dryRun ? ' (dry-run — no changes saved)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Most recent activity for the kit *during this shift* — the later of its
     * last scan and its last GPS fix on or after `$since` (the shift's
     * `started_at`). Pre-shift rows are ignored. Null still falls back to
     * `started_at` at the call site.
     *
     * Scans are read via {@see ClickhouseScan::forWindow()} (`scans_hot` /
     * `scans_cold`), keyed by `org_id` + `kit_id` so the live primary key can prune.
     * A scan-side ClickHouse error is allowed to throw so the per-shift catch
     * leaves the shift open rather than treating a miss as "no activity".
     * GPS stays on `clarity.sc_positions` and still degrades to null.
     */
    private function latestActivityFor(int $kitId, int $orgId, Carbon $since): ?Carbon
    {
        $scanAt = $this->latestScanFor($kitId, $orgId, $since);
        $gpsAt = $this->latestGpsFor($kitId, $since);

        $latest = null;
        foreach ([$scanAt, $gpsAt] as $candidate) {
            if ($candidate !== null && ($latest === null || $candidate->gt($latest))) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /**
     * Latest visible-window scan for the kit from `scans_hot` (or `scans_cold`
     * when the shift started more than 90 days ago). `org_id` is load-bearing:
     * live ORDER BY is `(org_id, scanned_at, kit_id, id)`.
     *
     * ClickHouse `max()` over an empty set returns epoch, not NULL — reject
     * that sentinel the same way {@see latestGpsFor()} does.
     */
    private function latestScanFor(int $kitId, int $orgId, Carbon $since): ?Carbon
    {
        $latest = ClickhouseScan::forWindow($since)->newQuery()
            ->final()
            ->forOrg($orgId)
            ->forKit($kitId)
            ->where('scanned_at', '>=', $since->copy()->utc()->toDateTimeString())
            ->max('scanned_at');

        if ($latest === null || $latest === '' || $latest === false) {
            return null;
        }

        $parsed = Carbon::parse((string) $latest, 'UTC');

        if ($parsed->timestamp <= 0 || $parsed->lt($since->copy()->utc())) {
            return null;
        }

        return $parsed;
    }

    /**
     * Latest scanner GPS fix for the kit from `clarity.sc_positions`, keyed by
     * `kit_mapping` (case-insensitive — platform lowercases it, the scanner feed
     * keeps its own casing) and bounded to the shift window. Degrades to null
     * when the kit has no mapping or ClickHouse is unreachable (e.g. the local
     * pgsql bridge has no such table), so the detector falls back to scans
     * alone rather than failing the run.
     */
    private function latestGpsFor(int $kitId, Carbon $since): ?Carbon
    {
        $mapping = Kit::query()->whereKey($kitId)->value('kit_mapping');

        if (! $mapping) {
            return null;
        }

        try {
            $latest = DB::clickhouse('sc_positions')
                ->whereRaw('lower(kit_mapping) = ?', [strtolower((string) $mapping)])
                ->where('timestamp_utc', '>=', $since->copy()->utc()->toDateTimeString())
                ->max('timestamp_utc');
        } catch (\Throwable) {
            return null;
        }

        if ($latest === null || $latest === '' || $latest === false) {
            return null;
        }

        $parsed = Carbon::parse((string) $latest, 'UTC');

        // ClickHouse max() over an empty set returns the DateTime default (epoch),
        // not NULL. Reject that sentinel — and any value before the shift window —
        // so we never stamp kit_shifts.ended_at as 1970-01-01.
        if ($parsed->timestamp <= 0 || $parsed->lt($since->copy()->utc())) {
            return null;
        }

        return $parsed;
    }
}
