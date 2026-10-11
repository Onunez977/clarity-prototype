<?php

declare(strict_types=1);

namespace App\Services\Scout;

use App\Http\Controllers\Api\ClarityScoutShiftController;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Scan;
use App\Models\Scopes\ScanTimezoneScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ensure there is exactly one open `kit_shifts` row for the given kit.
 *
 * Called from {@see ClarityScoutShiftController}
 * (both the status and end-shift endpoints) so a shift row is materialized
 * lazily — we don't need a CDC trigger on scan ingestion.
 *
 * `started_at` is inferred from the kit's recent scan history: it's the
 * earliest scan in the current continuous activity window (i.e. the most
 * recent scan run that doesn't have a ≥ 3-hour gap inside it). If the kit
 * has no recent activity, the shift starts at "now" — a zero-activity
 * shift that the assumed-end detector or the driver themselves can close.
 *
 * Race safety: the platform-schema partial unique index
 * `kit_shifts_one_open_per_kit` guarantees at most one open row per kit.
 * We additionally wrap in a transaction with row-level locking so concurrent
 * callers see a consistent state.
 */
final class EnsureOpenShift
{
    private const INACTIVITY_GAP_MINUTES = 180;

    private const LOOKBACK_HOURS = 24;

    private const MAX_SCAN_ROWS = 5000;

    /**
     * Force an open shift to exist for the kit, creating one if needed.
     *
     * Used by the `end()` endpoint and the scheduled command — both need a
     * row to close, even if the driver has zero activity.
     *
     * Race-safety: the partial unique index `kit_shifts_one_open_per_kit`
     * guarantees at most one open row per kit at the DB level. We can't
     * `lockForUpdate` a row that doesn't exist yet, so two concurrent
     * callers can both pass the `whereNull('ended_at')` check and both
     * attempt INSERT — the second will hit the unique index. We catch
     * that, re-read the now-existing row, and return it.
     */
    public function forKit(Kit $kit): KitShift
    {
        return DB::connection('platform')->transaction(function () use ($kit): KitShift {
            $existing = KitShift::query()
                ->where('kit_id', $kit->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $lastClosedEndedAt = KitShift::query()
                ->where('kit_id', $kit->id)
                ->whereNotNull('ended_at')
                ->orderByDesc('ended_at')
                ->value('ended_at');

            try {
                return KitShift::create([
                    'kit_id' => $kit->id,
                    'org_id' => $kit->org_id,
                    'started_at' => $this->inferStartedAt(
                        $kit->id,
                        Carbon::now(),
                        $lastClosedEndedAt ? Carbon::parse($lastClosedEndedAt) : null,
                    ),
                ]);
            } catch (QueryException $e) {
                // Concurrent caller won the INSERT race — re-read the row they created.
                $raced = KitShift::query()
                    ->where('kit_id', $kit->id)
                    ->whereNull('ended_at')
                    ->first();

                if ($raced) {
                    return $raced;
                }

                // Index conflict but the row is gone? Re-raise — something else is wrong.
                throw $e;
            }
        });
    }

    /**
     * Return the kit's currently open shift if one exists, otherwise return
     * null. Lazily opens a *new* shift only when there has been scan activity
     * **after** the kit's most-recently closed shift and within the last 3 hours.
     *
     * This is what the PWA's `/shift/status` endpoint reads, so the "End Shift"
     * button only appears when the driver is actually in an active window.
     * After a confirmed end-shift, status will read null until the driver
     * scans again — which then implicitly starts a fresh shift (matching
     * Oscar's "multiple shifts per day" spec).
     */
    public function currentOpenForKit(Kit $kit): ?KitShift
    {
        $existing = KitShift::query()
            ->where('kit_id', $kit->id)
            ->whereNull('ended_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $now = Carbon::now();
        $lastClosed = KitShift::query()
            ->where('kit_id', $kit->id)
            ->whereNotNull('ended_at')
            ->orderByDesc('ended_at')
            ->first();

        $newActivityBoundary = $lastClosed?->ended_at ?? $now->copy()->subYears(10);

        $hasNewActivity = Scan::query()
            ->withoutGlobalScope(ScanTimezoneScope::class)
            ->where('kit_id', $kit->id)
            ->where('scanned_at', '>', $newActivityBoundary)
            ->where('scanned_at', '>=', $now->copy()->subMinutes(self::INACTIVITY_GAP_MINUTES))
            ->exists();

        if (! $hasNewActivity) {
            return null;
        }

        return $this->forKit($kit);
    }

    private function inferStartedAt(int $kitId, Carbon $now, ?Carbon $notBefore = null): Carbon
    {
        $clamp = fn (Carbon $start): Carbon => $notBefore !== null && $start->lt($notBefore)
            ? $notBefore->copy()
            : $start;

        $rows = Scan::query()
            ->withoutGlobalScope(ScanTimezoneScope::class)
            ->select('scanned_at')
            ->where('kit_id', $kitId)
            ->where('scanned_at', '>=', $now->copy()->subHours(self::LOOKBACK_HOURS))
            ->orderBy('scanned_at')
            ->limit(self::MAX_SCAN_ROWS)
            ->pluck('scanned_at');

        if ($rows->isEmpty()) {
            return $clamp($now);
        }

        $scanTimes = $rows->map(fn ($t) => Carbon::parse($t))->values();

        $shiftStart = $scanTimes->first();
        $lastSeen = $shiftStart;

        foreach ($scanTimes as $t) {
            // abs() guards Carbon 3's signed diffInMinutes so the gap-split branch still fires on ascending times.
            if (abs($t->diffInMinutes($lastSeen)) > self::INACTIVITY_GAP_MINUTES) {
                $shiftStart = $t;
            }
            $lastSeen = $t;
        }

        // A stale latest scan (older than the gap) means the new shift is a fresh start.
        if (abs($lastSeen->diffInMinutes($now)) > self::INACTIVITY_GAP_MINUTES) {
            return $clamp($now);
        }

        return $clamp($shiftStart);
    }
}
