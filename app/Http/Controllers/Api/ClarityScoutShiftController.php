<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Activity\ActivityFactory;
use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Enums\ShiftEndTypeEnum;
use App\Facades\Analytics;
use App\Http\Controllers\Controller;
use App\Jobs\Scout\SendEndOfShiftSummary;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\User;
use App\Services\Scout\EnsureOpenShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Driver-facing shift endpoints for the Clarity Scout PWA.
 *
 *   POST /api/scout/shift/end     — confirmed shift end (driver tapped the
 *                                   "End Shift" button)
 *   GET  /api/scout/shift/status  — returns whether the kit's current shift
 *                                   is open, so the UI can hide/show the
 *                                   End Shift button.
 *
 * Both endpoints lazily materialize an open `kit_shifts` row via
 * {@see EnsureOpenShift}, so the shift exists before
 * we try to close it. Exception: a force-login impersonator
 * ({@see User::isScoutForceImpersonator()}) short-circuits both endpoints
 * before any shift is materialized or closed.
 */
class ClarityScoutShiftController extends Controller
{
    public function __construct(
        private readonly EnsureOpenShift $ensureOpenShift,
    ) {}

    /**
     * Report whether the kit's current shift is open.
     *
     * This GET intentionally has a side effect: {@see EnsureOpenShift::currentOpenForKit()}
     * lazily materializes an open `kit_shifts` row when recent scan activity
     * exists. That lazy create is the PWA's ONLY shift-start signal (the first
     * poll after new activity flips `wasRecentlyCreated`, which fires the
     * ShiftStart analytics + audit log below). Do not "fix" this into a pure
     * read — the shift would then never open for the driver. The one deliberate
     * exception: a force-login impersonator ({@see User::isScoutForceImpersonator()})
     * must never open a shift, so that lazy-open is short-circuited below.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $kit = Kit::query()->find($user->kit_id);

        if (! $kit) {
            return response()->json(['message' => 'No kit assigned'], 403);
        }

        if ($user->isScoutForceImpersonator()) {
            return response()->json([
                'data' => [
                    'shift_id' => null,
                    'open' => false,
                    'started_at' => null,
                    'ended_at' => null,
                    'end_type' => null,
                ],
            ]);
        }

        $shift = $this->ensureOpenShift->currentOpenForKit($kit);

        // A shift opens lazily the first time `status` is polled after new scan
        // activity; `wasRecentlyCreated` is the only PWA-observable "shift start".
        if ($shift !== null && $shift->wasRecentlyCreated) {
            Analytics::track(ScoutAnalyticsEvent::ShiftStart->value, $user, ['action' => 'start', 'shift_id' => $shift->id], page: ScoutAnalyticsPage::Shift->value);
            $this->logShiftActivity($shift, $user, 'Scout shift started', 'start');
        }

        if ($shift === null) {
            return response()->json([
                'data' => [
                    'shift_id' => null,
                    'open' => false,
                    'started_at' => null,
                    'ended_at' => null,
                    'end_type' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'shift_id' => $shift->id,
                'open' => $shift->isOpen(),
                'started_at' => $shift->started_at->toIso8601String(),
                'ended_at' => $shift->ended_at?->toIso8601String(),
                'end_type' => $shift->end_type?->value,
            ],
        ]);
    }

    public function end(Request $request): JsonResponse
    {
        $user = $request->user();
        $kit = Kit::query()->find($user->kit_id);

        if (! $kit) {
            return response()->json(['message' => 'No kit assigned'], 403);
        }

        if ($user->isScoutForceImpersonator()) {
            return response()->json([
                'data' => [
                    'shift_id' => null,
                    'open' => false,
                    'started_at' => null,
                    'ended_at' => null,
                    'end_type' => null,
                    'newly_closed' => false,
                ],
            ]);
        }

        Analytics::track(ScoutAnalyticsEvent::ShiftEnd->value, $user, ['action' => 'confirm-end'], page: ScoutAnalyticsPage::Shift->value);

        // Idempotent: an already-closed latest shift is returned as-is (no re-open on
        // double-tap); a kit with no history gets a zero-activity shift closed for the paper trail.
        $closed = DB::connection('platform')->transaction(function () use ($kit) {
            $latest = KitShift::query()
                ->where('kit_id', $kit->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latest !== null && $latest->isClosed()) {
                return [$latest, false];
            }

            $shift = $latest ?? $this->ensureOpenShift->forKit($kit);

            $shift->forceFill([
                'ended_at' => now(),
                'end_type' => ShiftEndTypeEnum::Confirmed,
            ])->save();

            return [$shift->fresh(), true];
        });

        [$shift, $newlyClosed] = $closed;

        if ($newlyClosed) {
            SendEndOfShiftSummary::dispatch($shift->id);
            $this->logShiftActivity($shift, $user, 'Scout shift ended', 'end');
        }

        return response()->json([
            'data' => [
                'shift_id' => $shift->id,
                'open' => false,
                'started_at' => $shift->started_at->toIso8601String(),
                'ended_at' => $shift->ended_at?->toIso8601String(),
                'end_type' => $shift->end_type?->value,
                'newly_closed' => $newlyClosed,
            ],
        ]);
    }

    /**
     * Write a Scout shift mutation to the Spatie audit trail, capturing the
     * acting driver plus impersonator / IP / user-agent in the same shape the
     * filter-activity logging uses ({@see ActivityFactory}).
     */
    private function logShiftActivity(KitShift $shift, User $user, string $description, string $action): void
    {
        $properties = [
            'action' => $action,
            'kit_id' => $shift->kit_id,
            'shift_id' => $shift->id,
            'started_at' => $shift->started_at?->toIso8601String(),
            'ended_at' => $shift->ended_at?->toIso8601String(),
            '_request' => [
                'clientIp' => request()->getClientIp(),
                'userAgent' => request()->userAgent(),
            ],
        ];

        if ($impersonator = session('impersonator')) {
            $properties['_impersonator'] = $impersonator;
        }

        activity('scout-shift')
            ->performedOn($shift)
            ->causedBy($user)
            ->withProperties($properties)
            ->log($description);
    }
}
