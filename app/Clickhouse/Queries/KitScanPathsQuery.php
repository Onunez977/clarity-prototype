<?php

namespace App\Clickhouse\Queries;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class KitScanPathsQuery extends ClickhouseQuery
{
    /**
     * Coordinate precision on the wire — matches
     * {@see KitRangePathsQuery}'s `COORDINATE_DECIMALS`, so a row here is
     * structurally identical to one from that query. Kit User Path now reads
     * this class as its sole source (DEV-2451); Bird's Eye and Scout Map
     * still call both, so the shapes must stay aligned.
     */
    private const COORDINATE_DECIMALS = 5;

    public const COLORS = [
        [128, 0, 0],
        [165, 42, 42],
        [220, 20, 60],
        [255, 99, 71],
        [205, 92, 92],
        [233, 150, 122],
        [255, 69, 0],
        [255, 140, 0],
        [255, 165, 0],
        [255, 215, 0],
        [189, 183, 107],
        [128, 128, 0],
        [154, 205, 50],
        [107, 142, 35],
        [0, 100, 0],
        [34, 139, 34],
        [0, 255, 0],
        [46, 139, 87],
        [60, 179, 113],
        [47, 79, 79],
        [0, 128, 128],
        [0, 139, 139],
        [0, 255, 255],
        [0, 191, 255],
        [25, 25, 112],
        [0, 0, 205],
        [0, 0, 255],
        [65, 105, 225],
        [138, 43, 226],
        [75, 0, 130],
        [72, 61, 139],
        [106, 90, 205],
        [147, 112, 219],
        [153, 50, 204],
        [128, 0, 128],
        [255, 0, 255],
        [218, 112, 214],
        [199, 21, 133],
        [255, 20, 147],
        [139, 69, 19],
        [210, 105, 30],
        [244, 164, 96],
    ];

    private static function colorsSql(): string
    {
        return implode(', ', array_map(
            fn ($c) => "({$c[0]}, {$c[1]}, {$c[2]})",
            self::COLORS
        ));
    }

    public static function handle(array $arguments = []): mixed
    {
        $layer = addslashes($arguments['layer'] ?? '');

        $query = self::filteredQuery($arguments)
            ->select(['kit_id'])
            ->selectRaw('arrayElement(['.self::colorsSql().'], (kit_id % 42) + 1) AS color')
            ->selectRaw("if(kit_name = '', concat('Kit ', toString(kit_id)), kit_name) AS name")
            ->selectRaw("'{$layer}' AS layerId")
            ->selectRaw('path_points AS path')
            // Date of the bucket (YYYY-MM-DD) so the client can fade older drives
            // by age (DEV-2116 Org Map historical overlay); the driver "today"
            // overlay tags is_today and ignores this.
            ->selectRaw('toString(toDate(timestamp)) AS trip_date');

        $query->orderBy('kit_id')
            ->orderBy('timestamp');

        return $query->get()->toArray();
    }

    /**
     * Total points {@see self::handle()} would return, as one integer, so a
     * caller can refuse an oversized selection before materializing it — same
     * shape as {@see KitRangePathsQuery::countPoints()}, used by its callers.
     * Shares {@see self::filteredQuery()} with handle(), so the count can
     * never drift from what handle() would actually hydrate.
     *
     * @param  array{org_id: int, kit_ids?: list<int>, start?: string, end?: string, timezone?: string, north?: float, south?: float, east?: float, west?: float}  $arguments
     */
    public static function countPoints(array $arguments = []): int
    {
        $row = self::filteredQuery($arguments)
            ->selectRaw('sum(length(path_points)) AS total_points')
            ->first();

        return (int) ($row['total_points'] ?? ($row->total_points ?? 0));
    }

    /**
     * Pre-encoded sibling of {@see self::handle()} for callers that must not
     * hydrate a PHP array per point (PG-022): each path arrives already
     * `toJSONString`-encoded, so cost is O(buckets) rather than O(points).
     * Shares {@see self::filteredQuery()}, so the predicates can never drift
     * from handle()'s. handle() itself is unchanged — three shipped callers
     * depend on its decoded `path` array.
     *
     * `trip_date` is dated in the viewer's timezone (whitelisted, see
     * {@see self::safeTimezone()}), matching {@see KitRangePathsQuery}'s own
     * `trip_date` label — Bird's Eye/Scout Map splice rows from both queries
     * into one response, so the two must keep meaning the same thing.
     *
     * @param  array{org_id: int, kit_ids?: list<int>, start?: string, end?: string, timezone?: string, north?: float, south?: float, east?: float, west?: float}  $arguments
     * @return list<array{kit_id: int, trip_date: string, path_json: string}>
     */
    public static function encodedPaths(array $arguments = []): array
    {
        $timezone = self::safeTimezone($arguments['timezone'] ?? 'UTC');
        $decimals = self::COORDINATE_DECIMALS;

        $query = self::filteredQuery($arguments)
            ->select(['kit_id'])
            ->selectRaw("toString(toDate(timestamp, '{$timezone}')) AS trip_date")
            ->selectRaw("toJSONString(arrayMap(p -> [round(tupleElement(p, 1), {$decimals}), round(tupleElement(p, 2), {$decimals})], path_points)) AS path_json");

        $query->orderBy('kit_id')
            ->orderBy('timestamp');

        $rows = [];

        foreach ($query->get() as $row) {
            $rows[] = [
                'kit_id' => (int) ($row['kit_id'] ?? $row->kit_id),
                'trip_date' => (string) ($row['trip_date'] ?? $row->trip_date),
                'path_json' => (string) ($row['path_json'] ?? $row->path_json),
            ];
        }

        return $rows;
    }

    /**
     * Fleet legacy aliases still stored on live viewer zones, keyed to the canonical
     * IANA id used when this PHP can no longer construct the alias. Same map as
     * {@see ScanPlateStatsBatchQuery::safeTimezone()}.
     *
     * PHP 8.4.25 may drop these from `timezone_identifiers_list(ALL_WITH_BC)` and from
     * constructability. When the alias still constructs, return it (identity-keep).
     * When it does not, the canonical id preserves the day-boundary
     * contract without throwing and without falling back to UTC.
     *
     * @var array<string, string>
     */
    private const LEGACY_FLEET_TIMEZONE_ALIASES = [
        'US/Central' => 'America/Chicago',
        'US/Eastern' => 'America/New_York',
        'US/Pacific' => 'America/Los_Angeles',
        'Asia/Calcutta' => 'Asia/Kolkata',
    ];

    /**
     * Timezones are interpolated into raw SQL, so only a real IANA identifier
     * is ever allowed through — {@see KitRangePathsQuery::safeTimezone()}
     * whitelists against the canonical-only `timezone_identifiers_list()`
     * (419 zones), which drops legacy aliases still in live use — `US/Central`,
     * `US/Eastern`, `US/Pacific`, `Asia/Calcutta` all fail that list and would
     * silently fall back to UTC, a 5-6 hour day-boundary shift. Whitelist
     * against `ALL_WITH_BC`, which ClickHouse resolves correctly. PHP 8.4.25
     * may drop those BC aliases from that list AND from constructability
     * (`new \DateTimeZone($alias)` throws). When the alias constructs, return
     * it (identity-keep). On `\DateInvalidTimeZoneException` — or
     * `\Exception` on older PHP — return the canonical id, never UTC, and never
     * throw. Do not probe with `timezone_open` (it warns). Scoped to this
     * method only — KitRangePathsQuery::safeTimezone() is untouched.
     */
    private static function safeTimezone(string $timezone): string
    {
        if (array_key_exists($timezone, self::LEGACY_FLEET_TIMEZONE_ALIASES)) {
            try {
                new \DateTimeZone($timezone);

                return $timezone;
            } catch (\DateInvalidTimeZoneException) {
                return self::LEGACY_FLEET_TIMEZONE_ALIASES[$timezone];
            } catch (\Exception) {
                return self::LEGACY_FLEET_TIMEZONE_ALIASES[$timezone];
            }
        }

        return in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $timezone : 'UTC';
    }

    /**
     * The row-selection predicates shared by handle() and countPoints(); no
     * SELECT list and no ordering, so either caller can add its own.
     *
     * @param  array{org_id: int, kit_ids?: list<int>, start?: string, end?: string, timezone?: string, north?: float, south?: float, east?: float, west?: float}  $arguments
     */
    private static function filteredQuery(array $arguments): Builder
    {
        $kitIds = $arguments['kit_ids'] ?? [];
        $start = $arguments['start'] ?? null;
        $end = $arguments['end'] ?? null;
        $timezone = $arguments['timezone'] ?? 'UTC';
        $north = $arguments['north'] ?? null;
        $south = $arguments['south'] ?? null;
        $east = $arguments['east'] ?? null;
        $west = $arguments['west'] ?? null;

        $query = DB::clickhouse('kit_scan_paths', final: true)
            ->where('org_id', (int) $arguments['org_id']);

        if (! empty($kitIds)) {
            $query->whereIntegerInRaw('kit_id', array_map('intval', $kitIds));
        }

        if ($start) {
            $query->where('timestamp', '>=', Carbon::parse($start, $timezone)->utc()->toDateTimeString());
        }

        if ($end) {
            $query->where('timestamp', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
        }

        $query->whereRaw('length(path_points) >= 2');

        // Optional viewport bbox (DEV-2185), additive/backward-compatible: a
        // bucket is kept if ANY of its points falls in the bbox. path_points
        // tuples are (lng, lat), matching the sc_positions geo Point encoding.
        // Must stay optional — KitUserPathController (non-Scout) shares this
        // query class and never passes a bbox.
        if ($north !== null && $south !== null && $east !== null && $west !== null) {
            $query->whereRaw(
                'arrayExists(p -> tupleElement(p, 2) BETWEEN ? AND ? AND tupleElement(p, 1) BETWEEN ? AND ?, path_points)',
                [(float) $south, (float) $north, (float) $west, (float) $east]
            );
        }

        return $query;
    }

    public static function getColor(int $kitId): array
    {
        return self::COLORS[$kitId % count(self::COLORS)];
    }

    public const STATE_BOUNDS = [
        'AL' => [30.22, 35.01, -88.47, -84.89],
        'AK' => [51.21, 71.39, -179.15, -129.98],
        'AZ' => [31.33, 37.00, -114.81, -109.04],
        'AR' => [33.00, 36.50, -94.62, -89.64],
        'CA' => [32.53, 42.01, -124.41, -114.13],
        'CO' => [36.99, 41.00, -109.06, -102.04],
        'CT' => [40.98, 42.05, -73.73, -71.79],
        'DE' => [38.45, 39.84, -75.79, -75.05],
        'FL' => [24.52, 31.00, -87.63, -80.03],
        'GA' => [30.36, 35.00, -85.61, -80.84],
        'HI' => [18.91, 28.40, -178.33, -154.81],
        'ID' => [41.99, 49.00, -117.24, -111.04],
        'IL' => [36.97, 42.51, -91.51, -87.50],
        'IN' => [37.77, 41.76, -88.10, -84.78],
        'IA' => [40.38, 43.50, -96.64, -90.14],
        'KS' => [36.99, 40.00, -102.05, -94.59],
        'KY' => [36.50, 39.15, -89.57, -81.96],
        'LA' => [28.93, 33.02, -94.04, -88.82],
        'ME' => [42.98, 47.46, -71.08, -66.95],
        'MD' => [37.91, 39.72, -79.49, -75.05],
        'MA' => [41.24, 42.89, -73.51, -69.93],
        'MI' => [41.70, 48.26, -90.42, -82.41],
        'MN' => [43.50, 49.38, -97.24, -89.49],
        'MS' => [30.17, 34.99, -91.66, -88.10],
        'MO' => [36.00, 40.61, -95.77, -89.10],
        'MT' => [44.36, 49.00, -116.05, -104.04],
        'NE' => [40.00, 43.00, -104.05, -95.31],
        'NV' => [35.00, 42.00, -120.01, -114.04],
        'NH' => [42.70, 45.31, -72.56, -70.70],
        'NJ' => [38.93, 41.36, -75.56, -73.89],
        'NM' => [31.33, 37.00, -109.05, -103.00],
        'NY' => [40.50, 45.02, -79.76, -71.86],
        'NC' => [33.84, 36.59, -84.32, -75.46],
        'ND' => [45.94, 49.00, -104.05, -96.55],
        'OH' => [38.40, 41.98, -84.82, -80.52],
        'OK' => [33.62, 37.00, -103.00, -94.43],
        'OR' => [41.99, 46.29, -124.57, -116.46],
        'PA' => [39.72, 42.27, -80.52, -74.69],
        'RI' => [41.15, 42.02, -71.86, -71.12],
        'SC' => [32.03, 35.22, -83.35, -78.54],
        'SD' => [42.48, 45.94, -104.06, -96.44],
        'TN' => [34.98, 36.68, -90.31, -81.65],
        'TX' => [25.84, 36.50, -106.65, -93.51],
        'UT' => [36.99, 42.00, -114.05, -109.04],
        'VT' => [42.73, 45.02, -73.44, -71.46],
        'VA' => [36.54, 39.47, -83.68, -75.24],
        'WA' => [45.54, 49.00, -124.85, -116.92],
        'WV' => [37.20, 40.64, -82.64, -77.72],
        'WI' => [42.49, 47.08, -92.89, -86.25],
        'WY' => [40.99, 45.01, -111.06, -104.05],
        'DC' => [38.79, 38.99, -77.12, -76.91],
    ];
}
