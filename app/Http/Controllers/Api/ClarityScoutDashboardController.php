<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Clickhouse\Models\KitTrip;
use App\Clickhouse\Models\Scan as ClickhouseScan;
use App\Clickhouse\Queries\ScoutCheckInRollupQuery;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Hotlist;
use App\Models\Org;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ClarityScoutDashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $kitId = $user->kit_id;
            $orgId = $user->org_id;
            $timezone = $user->timezone;

            $range = $request->query('range', 'today');
            [$start, $end] = $this->getDateRange($range, $timezone);

            $cacheKey = "scanner:stats:{$orgId}:{$kitId}:{$range}";
            // 'today' carries the live tiles, polled every ~10s — a short TTL keeps
            // the counts near-live while still coalescing concurrent ticks. The
            // historical ranges aren't polled, so they keep their longer TTLs.
            $ttl = match ($range) {
                'last_60' => now()->addMinutes(15),
                'last_30' => now()->addMinutes(5),
                'last_15' => now()->addMinutes(3),
                'today' => now()->addSeconds(10),
                default => now()->addMinutes(2),
            };

            $data = Cache::remember($cacheKey, $ttl, function () use ($kitId, $orgId, $start, $end, $timezone) {
                $scanModel = ClickhouseScan::forWindow($start);
                $hitModel = ClickhouseHit::forWindow($start);

                $scansCount = $scanModel->newQuery()
                    ->final()
                    ->forOrg($orgId)
                    ->forKit($kitId)
                    ->between($start, $end, $timezone)
                    ->count();

                $repossessed = HotlistStatusEnum::Repossessed->value;
                $live = HitTypeEnum::Live->value;
                $staging = HitTypeEnum::Staging_Live->value;

                // One round-trip for all hit metrics. direct_hits / staging_hits count
                // DISTINCT (plate, hour) pairs, so repeated scans of the same plate
                // collapse to one per hour bucket (DEV-2077). repos_affected counts
                // distinct repossessed hotlists over the UN-deduped set — a plate hitting
                // two repossessed hotlists in one hour is two affected repos; staging is
                // excluded there by design (DEV-2053).
                $hitCounts = $hitModel->newQuery()
                    ->final()
                    ->forKitOrg($orgId)
                    ->where('kit_id', $kitId)
                    ->forHitTypes([$live, $staging])
                    ->forCreatedBetween($start, $end)
                    ->validDisposition()
                    ->selectRaw("uniqExactIf((ocr, toStartOfHour(hit_created_at)), hit_type = '{$live}') AS direct_hits, uniqExactIf((ocr, toStartOfHour(hit_created_at)), hit_type = '{$staging}') AS staging_hits, uniqExactIf(hotlist_id, hotlist_status = {$repossessed} AND hit_type = '{$live}') AS repos_affected")
                    ->first();

                $directHits = (int) ($hitCounts->direct_hits ?? 0);
                $stagingHits = (int) ($hitCounts->staging_hits ?? 0);
                $reposAffected = (int) ($hitCounts->repos_affected ?? 0);

                // Trips are gated behind the clarity_scout_trips flag (default off):
                // skip the kit_trips query entirely and report 0 when it's disabled.
                $tripCount = config('app.experimental.clarity_scout_trips', false)
                    ? KitTrip::query()
                        ->forOrg($orgId)
                        ->forKit($kitId)
                        ->betweenDates($start->toDateString(), $end->toDateString())
                        ->count()
                    : 0;

                return [
                    'scans_today' => $scansCount,
                    'direct_hits' => $directHits,
                    'staging_hits' => $stagingHits,
                    'repos_affected' => $reposAffected,
                    'trip_count' => $tripCount,
                ];
            });

            return response()->json([
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load dashboard stats.',
            ], 500);
        }
    }

    public function recentHits(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $kitId = $user->kit_id;
            $orgId = $user->org_id;

            $range = $request->query('range', 'today');
            [$start, $end] = $this->getDateRange($range, $user->timezone);

            // Short fixed TTL; 10s under impersonation so admins see near-live
            // data (mirrors ClickhouseQuery::query). Range is in the cache key so
            // switching the filter can't serve another range's list (DEV-2137).
            $ttl = (session()?->has('admin_impersonator') ?? false)
                ? now()->addSeconds(10)
                : now()->addSeconds(90);

            $hits = Cache::remember("scanner:recent-hits:{$orgId}:{$kitId}:{$range}", $ttl, function () use ($orgId, $kitId, $start, $end) {
                // Reflect each order's CURRENT hotlist status (FINAL semi-join),
                // not the frozen per-hit column (DEV-2117); the semi-join keeps
                // only currently-Open orders. Single-kit driver scope preserved.
                // No ->limit(20): main removed the pending-hits cap (95da4d7f).
                // liveActionable() is the base-Hits-page rule (NULL/New Hit or
                // Accessible, DEV-2059 correction) — stats() below keeps the
                // analytics validDisposition() rule (NULL/Valid) unchanged.
                $scopedHits = fn () => ClickhouseHit::hot()->newQuery()
                    ->final()
                    ->forKitOrg((int) $orgId)
                    ->where('kit_id', $kitId)
                    ->liveActionable()
                    ->forCreatedBetween($start, $end);

                $candidateHotlistIds = $scopedHits()->distinct()->pluck('hotlist_id')->unique()->values()->all();

                $openHotlistIds = $candidateHotlistIds === []
                    ? []
                    : ClickhouseHotlist::query()
                        ->whereIntegerInRaw('id', $candidateHotlistIds)
                        ->where('status', HotlistStatusEnum::Open->value)
                        ->pluck('id')
                        ->all();

                return $openHotlistIds === []
                    ? []
                    : $scopedHits()
                        ->whereIntegerInRaw('hotlist_id', $openHotlistIds)
                        ->orderByDesc('hit_created_at')
                        ->limitBy(1, 'ocr')
                        ->get()
                        ->map(fn ($hit) => [
                            'id' => $hit->id,
                            'scan_id' => $hit->scan_id,
                            'plate' => $hit->ocr ?: 'N/A',
                            'hit_time' => Carbon::parse($hit->hit_created_at, 'UTC')->toISOString(),
                            'hit_type' => $hit->hit_type,
                            'status' => HotlistStatusEnum::Open->title(),
                            // DEV-2059 F1: the CH flat column — null for New Hit, else the
                            // driver-set string (e.g. 'Accessible') — so the hit-detail modal
                            // can seed the disposition control from the saved value on re-open
                            // instead of resetting to the placeholder every time.
                            'disposition' => $hit->disposition,
                            'plate_image' => scan_img($hit->scan_guid, 'p'),
                            'vehicle_image' => scan_img($hit->scan_guid, 'c'),
                            'lat' => $hit->lat ? (float) $hit->lat : null,
                            'lng' => $hit->lng ? (float) $hit->lng : null,
                            // DEV-2200: vehicle/state fields (grafted from main into the H4 semi-join projection).
                            'vehicle_year' => $hit->vehicle_year,
                            'vehicle_make' => $hit->vehicle_make,
                            'vehicle_model' => $hit->vehicle_model,
                            'hotlist_us_state' => $hit->hotlist_us_state,
                        ])
                        ->all();
            });

            return response()->json([
                'data' => $hits,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load recent hits.',
            ], 500);
        }
    }

    public function hotlistStats(Request $request): JsonResponse
    {
        try {
            $orgId = $request->user()->org_id;

            $openCount = Hotlist::forOrg($orgId)
                ->open()
                ->whereRaw('length(plate) >= 3')
                ->count();

            $stagedCount = Hotlist::query()
                ->join('approval_list', function ($join) use ($orgId) {
                    $join->on('approval_list.staging_org_id', '=', 'hotlist.org_id')
                        ->where('approval_list.agent_org_id', '=', $orgId);
                })
                ->staged()
                ->where('status', HotlistStatusEnum::Open->value)
                ->whereRaw('length(plate) >= 3')
                ->count();

            return response()->json([
                'data' => [
                    'open' => $openCount,
                    'staged' => $stagedCount,
                    'total_active' => $openCount + $stagedCount,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load hotlist stats.',
            ], 500);
        }
    }

    public function checkInCounts(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $timezone = $user->timezone;
            $range = $request->query('range', 'today');

            [$start, $end] = $this->getDateRange($range, $timezone);

            $ttl = match ($range) {
                'last_60' => now()->addMinutes(15),
                'last_30' => now()->addMinutes(5),
                'last_15' => now()->addMinutes(3),
                default => now()->addMinutes(2),
            };

            $stagingOrgIds = Org::find($user->org_id)?->stagingOrgIds()->toArray() ?? [];

            $data = ScoutCheckInRollupQuery::query([
                'org_id' => $user->org_id,
                'kit_id' => $user->kit_id,
                'staging_org_ids' => $stagingOrgIds,
                'start' => $start->utc()->toDateTimeString(),
                'end' => $end->utc()->toDateTimeString(),
                'range' => $range,
                'cacheTTL' => $ttl,
            ], cache: true);

            return response()->json([
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load check-in counts.',
            ], 500);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function getDateRange(string $range, string $timezone): array
    {
        $now = Carbon::now($timezone);

        return match ($range) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'last_15' => [$now->copy()->subDays(15)->startOfDay(), $now->copy()->endOfDay()],
            'last_30' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            'last_60' => [$now->copy()->subDays(60)->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
