<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Clickhouse\Models\KitTrip;
use App\Clickhouse\Models\Scan as ClickhouseScan;
use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ClarityScoutTripsController extends Controller
{
    private const IDLE_GAP_MINUTES = 30;

    private function ensureTripsEnabled(): void
    {
        abort_unless(config('app.experimental.clarity_scout_trips', false), 404);
    }

    private function parseCoordinates(mixed $coordinates): array
    {
        if (is_string($coordinates)) {
            return json_decode($coordinates, true) ?? [];
        }

        return is_array($coordinates) ? $coordinates : [];
    }

    public function current(Request $request): JsonResponse
    {
        $this->ensureTripsEnabled();

        try {
            $user = $request->user();
            $kitId = $user->kit_id;
            $orgId = $user->org_id;
            $timezone = $user->timezone;

            $now = Carbon::now($timezone);
            $today = $now->copy()->toDateString();

            $trip = KitTrip::query()
                ->select(['trip_start', 'trip_end', 'duration_minutes'])
                ->withCoordinates()
                ->forOrg($orgId)
                ->forKit($kitId)
                ->onDate($today)
                ->orderByDesc('trip_start')
                ->first();

            if (! $trip) {
                return response()->json(['data' => null]);
            }

            $trip = (object) $trip;
            $tripStart = Carbon::parse($trip->trip_start, 'UTC');
            $tripEnd = Carbon::parse($trip->trip_end, 'UTC');
            $isActive = abs($now->utc()->diffInMinutes($tripEnd)) < self::IDLE_GAP_MINUTES;

            $startStr = addslashes($tripStart->toDateTimeString());
            $endStr = addslashes($isActive ? $now->utc()->toDateTimeString() : $tripEnd->toDateTimeString());

            $scansCount = ClickhouseScan::forWindow($tripStart)->newQuery()
                ->final()
                ->forOrg($orgId)
                ->forKit($kitId)
                ->whereRaw("scanned_at >= '{$startStr}'")
                ->whereRaw("scanned_at <= '{$endStr}'")
                ->count();

            $hitsCount = ClickhouseHit::query()
                ->final()
                ->forKitOrg($orgId)
                ->where('kit_id', $kitId)
                ->liveValid()
                ->whereRaw("hit_created_at >= '{$startStr}'")
                ->whereRaw("hit_created_at <= '{$endStr}'")
                ->count();

            $coordinates = $this->parseCoordinates($trip->coordinates);
            $miles = $this->calculateMiles($coordinates);

            return response()->json([
                'data' => [
                    'scans' => $scansCount,
                    'hits' => $hitsCount,
                    'miles_driven' => round($miles, 1),
                    'active_duration_minutes' => (int) $trip->duration_minutes,
                    'started_at' => $tripStart->toISOString(),
                    'ended_at' => $isActive ? null : $tripEnd->toISOString(),
                    'is_active' => $isActive,
                    'path' => $coordinates,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load current trip.',
            ], 500);
        }
    }

    public function browse(Request $request): JsonResponse
    {
        $this->ensureTripsEnabled();

        try {
            $request->validate([
                'start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            ]);

            // Cap the span so a multi-year window can't pluck millions of cold-table
            // scan/hit timestamps into PHP memory. kit_trips is TTL'd to 90 days, so a
            // wider window returns no extra trips anyway.
            if (Carbon::parse($request->query('start_date'))->addDays(90)->lt(Carbon::parse($request->query('end_date')))) {
                return response()->json([
                    'message' => 'Date range may not exceed 90 days.',
                ], 422);
            }

            $user = $request->user();
            $kitId = $user->kit_id;
            $orgId = $user->org_id;
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            $isRecent = $endDate >= Carbon::now($user->timezone)->toDateString();
            $cacheKey = "scanner:trips:browse:{$orgId}:{$kitId}:{$startDate}:{$endDate}";
            $ttl = $isRecent ? now()->addMinutes(2) : now()->addMinutes(15);

            $start = Carbon::parse($startDate, $user->timezone)->startOfDay();
            $end = Carbon::parse($endDate, $user->timezone)->endOfDay();

            $data = Cache::remember($cacheKey, $ttl, fn () => $this->buildTripSummaries($kitId, $orgId, $start, $end));

            $hasMore = KitTrip::query()
                ->forOrg($orgId)
                ->forKit($kitId)
                ->before($startDate)
                ->exists();

            return response()->json([
                'data' => $data,
                'meta' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'has_more' => $hasMore,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load trips.',
            ], 500);
        }
    }

    private function buildTripSummaries(int $kitId, int $orgId, Carbon $start, Carbon $end): array
    {
        $trips = KitTrip::query()
            ->select(['trip_date', 'trip_start', 'trip_end', 'duration_minutes'])
            ->withCoordinates()
            ->forOrg($orgId)
            ->forKit($kitId)
            ->betweenDates($start->toDateString(), $end->toDateString())
            ->orderBy('trip_start')
            ->get();

        if ($trips->isEmpty()) {
            return [];
        }

        $trips = $trips->map(fn ($t) => (object) $t);

        $startStr = addslashes($start->copy()->utc()->toDateTimeString());
        $endStr = addslashes($end->copy()->utc()->toDateTimeString());

        $scanTimestamps = ClickhouseScan::forWindow($start)->newQuery()
            ->final()
            ->forOrg($orgId)
            ->forKit($kitId)
            ->whereRaw("scanned_at >= '{$startStr}'")
            ->whereRaw("scanned_at <= '{$endStr}'")
            ->pluck('scanned_at')
            ->map(fn ($ts) => strtotime((string) $ts))
            ->sort()
            ->values();

        $hitTimestamps = ClickhouseHit::query()
            ->final()
            ->forKitOrg($orgId)
            ->where('kit_id', $kitId)
            ->liveValid()
            ->whereRaw("hit_created_at >= '{$startStr}'")
            ->whereRaw("hit_created_at <= '{$endStr}'")
            ->pluck('hit_created_at')
            ->map(fn ($ts) => strtotime((string) $ts))
            ->sort()
            ->values();

        $dayCounters = [];
        $tripSummaries = [];

        foreach ($trips as $trip) {
            $tripStart = Carbon::parse($trip->trip_start, 'UTC');
            $tripEnd = Carbon::parse($trip->trip_end, 'UTC');
            $date = Carbon::parse($trip->trip_date)->format('Y-m-d');
            $tripStartTs = $tripStart->timestamp;
            $tripEndTs = $tripEnd->timestamp;

            $dayCounters[$date] = ($dayCounters[$date] ?? -1) + 1;

            $scansCount = $scanTimestamps
                ->filter(fn ($ts) => $ts >= $tripStartTs && $ts <= $tripEndTs)
                ->count();

            $hitsCount = $hitTimestamps
                ->filter(fn ($ts) => $ts >= $tripStartTs && $ts <= $tripEndTs)
                ->count();

            $coordinates = $this->parseCoordinates($trip->coordinates);
            $miles = $this->calculateMiles($coordinates);

            $tripSummaries[] = [
                'date' => $date,
                'trip_index' => $dayCounters[$date],
                'scans' => $scansCount,
                'hits' => $hitsCount,
                'miles_driven' => round($miles, 1),
                'active_duration_minutes' => (int) $trip->duration_minutes,
                'started_at' => $tripStart->toISOString(),
                'ended_at' => $tripEnd->toISOString(),
            ];
        }

        usort($tripSummaries, function ($a, $b) {
            $dateCompare = strcmp($b['date'], $a['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return $b['trip_index'] - $a['trip_index'];
        });

        return $tripSummaries;
    }

    public function day(Request $request, string $date): JsonResponse
    {
        $this->ensureTripsEnabled();

        try {
            validator(['date' => $date], [
                'date' => ['required', 'date_format:Y-m-d'],
            ])->validate();

            $user = $request->user();
            Analytics::track(ScoutAnalyticsEvent::DayDetail->value, $user, ['action' => 'view-day', 'date' => $date], page: ScoutAnalyticsPage::Trips->value);
            $kitId = $user->kit_id;
            $orgId = $user->org_id;

            $isToday = $date === Carbon::now($user->timezone)->toDateString();
            $cacheKey = "scanner:day:{$orgId}:{$kitId}:{$date}:v2";
            $ttl = $isToday ? now()->addMinutes(2) : now()->addMinutes(15);

            $data = Cache::remember($cacheKey, $ttl, function () use ($kitId, $orgId, $date) {
                $trips = KitTrip::query()
                    ->select(['trip_start', 'trip_end', 'duration_minutes'])
                    ->withCoordinates()
                    ->forOrg($orgId)
                    ->forKit($kitId)
                    ->onDate($date)
                    ->orderBy('trip_start')
                    ->get();

                if ($trips->isEmpty()) {
                    return null;
                }

                $trips = $trips->map(fn ($t) => (object) $t);

                $earliestStart = Carbon::parse($trips->first()->trip_start, 'UTC');
                $latestEnd = Carbon::parse($trips->last()->trip_end, 'UTC');

                $startStr = addslashes($earliestStart->toDateTimeString());
                $endStr = addslashes($latestEnd->toDateTimeString());

                $scanTimestamps = ClickhouseScan::forWindow($earliestStart)->newQuery()
                    ->final()
                    ->forOrg($orgId)
                    ->forKit($kitId)
                    ->whereRaw("scanned_at >= '{$startStr}'")
                    ->whereRaw("scanned_at <= '{$endStr}'")
                    ->pluck('scanned_at')
                    ->map(fn ($ts) => strtotime((string) $ts))
                    ->sort()
                    ->values();

                $hits = ClickhouseHit::query()
                    ->final()
                    ->select(['ocr', 'hit_created_at', 'hit_type', 'lat', 'lng', 'scan_guid', 'vehicle_year', 'vehicle_make', 'vehicle_model', 'hotlist_us_state'])
                    ->forKitOrg($orgId)
                    ->where('kit_id', $kitId)
                    ->liveValid()
                    ->whereRaw("hit_created_at >= '{$startStr}'")
                    ->whereRaw("hit_created_at <= '{$endStr}'")
                    ->orderBy('hit_created_at')
                    ->get()
                    ->map(fn ($h) => (object) $h);

                $colors = ['#3B82F6', '#EF4444', '#22C55E', '#A855F7', '#F59E0B', '#06B6D4', '#EC4899', '#84CC16', '#FB923C', '#14B8A6'];

                $tripSummaries = [];
                $hitsDetail = [];
                $totalScans = 0;
                $totalHits = 0;
                $totalMiles = 0.0;
                $totalDuration = 0;

                foreach ($trips as $index => $trip) {
                    $tripStart = Carbon::parse($trip->trip_start, 'UTC');
                    $tripEnd = Carbon::parse($trip->trip_end, 'UTC');
                    $tripStartTs = $tripStart->timestamp;
                    $tripEndTs = $tripEnd->timestamp;
                    $color = $colors[$index % count($colors)];

                    $scansCount = $scanTimestamps
                        ->filter(fn ($ts) => $ts >= $tripStartTs && $ts <= $tripEndTs)
                        ->count();

                    $coordinates = $this->parseCoordinates($trip->coordinates);

                    $tripHits = $hits->filter(function ($h) use ($tripStartTs, $tripEndTs, $coordinates) {
                        $ts = strtotime((string) $h->hit_created_at);

                        if ($ts < $tripStartTs || $ts > $tripEndTs) {
                            return false;
                        }

                        return $this->isWithinCorridor((float) $h->lat, (float) $h->lng, $coordinates);
                    });
                    $miles = $this->calculateMiles($coordinates);

                    $tripSummaries[] = [
                        'trip_index' => $index,
                        'scans' => $scansCount,
                        'hits' => $tripHits->count(),
                        'miles_driven' => round($miles, 1),
                        'active_duration_minutes' => (int) $trip->duration_minutes,
                        'started_at' => $tripStart->toISOString(),
                        'ended_at' => $tripEnd->toISOString(),
                        'path' => $coordinates,
                    ];

                    foreach ($tripHits as $hit) {
                        $hitsDetail[] = [
                            'plate' => $hit->ocr ?: 'N/A',
                            'time' => Carbon::parse($hit->hit_created_at, 'UTC')->toISOString(),
                            'type' => $hit->hit_type,
                            'lat' => (float) $hit->lat,
                            'lng' => (float) $hit->lng,
                            'plate_image' => scan_img($hit->scan_guid, 'p'),
                            'vehicle_image' => scan_img($hit->scan_guid, 'c'),
                            'vehicle_year' => $hit->vehicle_year,
                            'vehicle_make' => $hit->vehicle_make,
                            'vehicle_model' => $hit->vehicle_model,
                            'hotlist_us_state' => $hit->hotlist_us_state,
                            'trip_index' => $index,
                            'markerColor' => $color,
                        ];
                    }

                    $totalScans += $scansCount;
                    $totalHits += $tripHits->count();
                    $totalMiles += $miles;
                    $totalDuration += (int) $trip->duration_minutes;
                }

                return [
                    'date' => $date,
                    'trips' => $tripSummaries,
                    'totals' => [
                        'scans' => $totalScans,
                        'hits' => $totalHits,
                        'miles_driven' => round($totalMiles, 1),
                        'active_duration_minutes' => $totalDuration,
                    ],
                    'hits_detail' => $hitsDetail,
                ];
            });

            if (! $data) {
                return response()->json(['message' => 'No trips found for this date.'], 404);
            }

            return response()->json(['data' => $data]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load day detail.',
            ], 500);
        }
    }

    public function show(Request $request, string $date, int $tripIndex): JsonResponse
    {
        $this->ensureTripsEnabled();

        try {
            validator(['date' => $date, 'trip_index' => $tripIndex], [
                'date' => ['required', 'date_format:Y-m-d'],
                'trip_index' => ['required', 'integer', 'min:0'],
            ])->validate();

            $user = $request->user();
            Analytics::track(ScoutAnalyticsEvent::TripDetail->value, $user, ['action' => 'view-detail', 'date' => $date, 'trip_index' => $tripIndex], page: ScoutAnalyticsPage::Trips->value);
            $kitId = $user->kit_id;
            $orgId = $user->org_id;

            // Get all trips for this date, ordered by start time, and pick by index
            $trips = KitTrip::query()
                ->select(['trip_start', 'trip_end', 'duration_minutes'])
                ->withCoordinates()
                ->forOrg($orgId)
                ->forKit($kitId)
                ->onDate($date)
                ->orderBy('trip_start')
                ->get();

            if ($trips->isEmpty() || ! isset($trips[$tripIndex])) {
                return response()->json([
                    'message' => 'Trip not found.',
                ], 404);
            }

            $trip = (object) $trips[$tripIndex];
            $tripStart = Carbon::parse($trip->trip_start, 'UTC');
            $tripEnd = Carbon::parse($trip->trip_end, 'UTC');

            $startStr = addslashes($tripStart->toDateTimeString());
            $endStr = addslashes($tripEnd->toDateTimeString());

            $coordinates = $this->parseCoordinates($trip->coordinates);
            $miles = $this->calculateMiles($coordinates);

            $scansCount = ClickhouseScan::forWindow($tripStart)->newQuery()
                ->final()
                ->forOrg($orgId)
                ->forKit($kitId)
                ->whereRaw("scanned_at >= '{$startStr}'")
                ->whereRaw("scanned_at <= '{$endStr}'")
                ->count();

            // Get hit details for this trip window (live hits only, within 200m of trip path)
            $hitsQuery = ClickhouseHit::query()
                ->final()
                ->select(['ocr', 'hit_created_at', 'hit_type', 'lat', 'lng', 'scan_guid', 'vehicle_year', 'vehicle_make', 'vehicle_model', 'hotlist_us_state'])
                ->forKitOrg($orgId)
                ->where('kit_id', $kitId)
                ->liveValid()
                ->whereRaw("hit_created_at >= '{$startStr}'")
                ->whereRaw("hit_created_at <= '{$endStr}'");

            $geoFilter = $this->buildPathGeoFilterRaw($coordinates);
            if ($geoFilter) {
                $hitsQuery->whereRaw($geoFilter);
            }

            $hits = $hitsQuery->orderBy('hit_created_at')->get();

            $hitsDetail = $hits->map(fn ($hit) => (object) $hit)->map(fn ($hit) => [
                'plate' => $hit->ocr ?: 'N/A',
                'time' => Carbon::parse($hit->hit_created_at, 'UTC')->toISOString(),
                'type' => $hit->hit_type,
                'lat' => (float) $hit->lat,
                'lng' => (float) $hit->lng,
                'plate_image' => scan_img($hit->scan_guid, 'p'),
                'vehicle_image' => scan_img($hit->scan_guid, 'c'),
                'vehicle_year' => $hit->vehicle_year,
                'vehicle_make' => $hit->vehicle_make,
                'vehicle_model' => $hit->vehicle_model,
                'hotlist_us_state' => $hit->hotlist_us_state,
            ])->values()->toArray();

            return response()->json([
                'data' => [
                    'date' => $date,
                    'trip_index' => $tripIndex,
                    'scans' => $scansCount,
                    'hits' => count($hitsDetail),
                    'miles_driven' => round($miles, 1),
                    'active_duration_minutes' => (int) $trip->duration_minutes,
                    'started_at' => $tripStart->toISOString(),
                    'ended_at' => $tripEnd->toISOString(),
                    'path' => $coordinates,
                    'hits_detail' => $hitsDetail,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to load trip detail.',
            ], 500);
        }
    }

    /**
     * Build a ClickHouse whereRaw fragment that filters hits within 200m of any path point.
     * Returns null if the path has no coordinates.
     */
    private function buildPathGeoFilterRaw(array $coordinates): ?string
    {
        if (empty($coordinates)) {
            return null;
        }

        $tuples = array_map(
            fn ($c) => '('.(float) $c[0].','.(float) $c[1].')',
            $coordinates
        );

        return 'arrayExists(p -> geoDistance(lng, lat, tupleElement(p, 1), tupleElement(p, 2)) <= 200, ['.implode(',', $tuples).'])';
    }

    /**
     * Check if a point is within a threshold distance of any point on a path (Haversine).
     */
    private function isWithinCorridor(float $lat, float $lng, array $pathCoordinates, float $thresholdMeters = 200): bool
    {
        if (empty($pathCoordinates)) {
            return true;
        }

        $earthRadiusMeters = 6371000.0;

        foreach ($pathCoordinates as $point) {
            $pLng = (float) $point[0];
            $pLat = (float) $point[1];

            $dLat = deg2rad($pLat - $lat);
            $dLng = deg2rad($pLng - $lng);

            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($pLat)) * sin($dLng / 2) ** 2;
            $distance = $earthRadiusMeters * 2 * atan2(sqrt($a), sqrt(1 - $a));

            if ($distance <= $thresholdMeters) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate total miles driven from an array of [lng, lat] coordinates using Haversine formula.
     */
    private function calculateMiles(array $coordinates): float
    {
        if (count($coordinates) < 2) {
            return 0.0;
        }

        $totalMiles = 0.0;
        $earthRadiusMiles = 3958.8;

        for ($i = 1; $i < count($coordinates); $i++) {
            $lng1 = deg2rad($coordinates[$i - 1][0]);
            $lat1 = deg2rad($coordinates[$i - 1][1]);
            $lng2 = deg2rad($coordinates[$i][0]);
            $lat2 = deg2rad($coordinates[$i][1]);

            $dlat = $lat2 - $lat1;
            $dlng = $lng2 - $lng1;

            $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

            $totalMiles += $earthRadiusMiles * $c;
        }

        return $totalMiles;
    }
}
