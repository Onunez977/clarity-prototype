<?php

namespace App\Clickhouse\Queries;

use App\Support\Scout\HistoricPathBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Assembles clarity.sc_positions GPS into rendered route segments for many kit
 * mappings over a date range, entirely inside ClickHouse (3s decimation ->
 * per-local-hour groupArray -> chronological sort -> distance-dedup -> head-cap
 * -> break split) and returns one row per segment with its path already
 * JSON-encoded, so PHP never materializes an individual point.
 *
 * The split reproduces {@see HistoricPathBuilder::build()} in SQL — same 500ft
 * break, same drop of <2-point segments, same local-hour boundary — for a
 * caller whose org-wide selections are far too large to segment in PHP. Scout's
 * Bird's Eye Org Map is now the only such caller; every other caller uses
 * HistoricPathBuilder directly. Kit User Path was the original caller but reads
 * scan paths only as of DEV-2451, pending DEV-2450.
 *
 * Optional viewport bbox (north/south/east/west) filters GPS at the
 * sc_positions read — same spirit as {@see KitHistoricPositionsQuery} — so
 * Org Map historical loads only assemble what's on screen (DEV-2374). Omit
 * all four to keep full-range assembly (no current caller does). Clipping can draw a
 * short chord across a brief out-of-box excursion when the exit/re-entry gap
 * is under the break distance; that is the viewport-load tradeoff vs keeping
 * whole segments that merely touch the box.
 *
 * {@see self::cachedHandle()} has had no caller since DEV-2451 removed Kit User
 * Path's tier-1 read; Bird's Eye calls `handle()` directly. Its `_mappings` /
 * `mappings_key` pair exists because kit_mapping values can contain `-`, which
 * collides under ClickhouseQuery's `-`-joined cache key. Kept, not deleted, so
 * DEV-2450 can restore a cached read without re-deriving it.
 */
final class KitRangePathsQuery extends ClickhouseQuery
{
    /** Pinned: widening decimation makes point spacing speed-dependent and shreds a route past the break distance, blanking the map (DEV-2177/DEV-2221). Widen DEDUP_METERS instead. */
    private const DECIMATION_SECONDS = 3;

    /** Per-(kit, hour) head-cap backstop (3600/3). Head-cap, not even-interval subsample — subsampling would widen spacing past the break. */
    private const POINTS_PER_SEGMENT_CAP = 1200;

    /** HistoricPathBuilder's 500ft break, in meters (geoDistance's unit). */
    private const BREAK_METERS = 152.4;

    /** Drop a point within this many meters of the last KEPT point, collapsing idle-scanner dwell. Callers widen it to bound a broad selection; it must stay well under BREAK_METERS. */
    private const DEFAULT_DEDUP_METERS = 5.0;

    /** Coordinate precision on the wire; ~1.1m, below the dedup floor, so it costs no visible fidelity. */
    private const COORDINATE_DECIMALS = 5;

    /**
     * @param  array{mappings?: list<string>, _mappings?: list<string>, start?: string, end?: string, dedup_meters?: float, timezone?: string, org_id?: int, mappings_key?: string, north?: float, south?: float, east?: float, west?: float}  $arguments
     * @return list<array{mapping: string, trip_date: string, path_json: string}>
     */
    public static function handle(array $arguments = []): array
    {
        [$mappings, $start, $end, $dedupMeters, $timezone, $bbox] = self::resolveArguments($arguments);

        if ($mappings === [] || $start === null || $end === null) {
            return [];
        }

        $rows = self::segmentsQuery($mappings, $start, $end, $dedupMeters, $timezone, $bbox)->get();

        $segments = [];

        foreach ($rows as $row) {
            $segments[] = [
                'mapping' => (string) ($row['mapping'] ?? $row->mapping),
                'trip_date' => (string) ($row['trip_date'] ?? $row->trip_date),
                'path_json' => (string) ($row['path_json'] ?? $row->path_json),
            ];
        }

        return $segments;
    }

    /**
     * Same 5-minute cache key shape as {@see ClickhouseQuery::query()} with
     * cache:true, but exceptions always propagate — never swallowed to [].
     *
     * @param  array{mappings?: list<string>, _mappings?: list<string>, start?: string, end?: string, dedup_meters?: float, timezone?: string, org_id?: int, mappings_key?: string, cacheTTL?: \DateTimeInterface|\DateInterval|int}  $arguments
     * @return list<array{mapping: string, trip_date: string, path_json: string}>
     */
    public static function cachedHandle(array $arguments): array
    {
        $cacheTTL = $arguments['cacheTTL'] ?? now()->addMinutes(5);

        if (session()?->has('admin_impersonator') ?? false) {
            $cacheTTL = now()->addSeconds(3);
        }

        return Cache::remember(
            self::buildCacheKey($arguments),
            $cacheTTL,
            fn () => self::handle($arguments)
        );
    }

    /**
     * Pre-flight point total {@see self::handle()} would render (post-dedup,
     * post-cap) as one integer. Still runs the same hourly assembly pipeline as
     * handle (not a cheap skim) — Org Map historical (DEV-2374) derives the
     * budget count from assembled segments instead of calling this first.
     * When a viewport bbox is present, the count is viewport-scoped too.
     *
     * @param  array{mappings?: list<string>, _mappings?: list<string>, start?: string, end?: string, dedup_meters?: float, timezone?: string, north?: float, south?: float, east?: float, west?: float}  $arguments
     */
    public static function countPoints(array $arguments = []): int
    {
        [$mappings, $start, $end, $dedupMeters, $timezone, $bbox] = self::resolveArguments($arguments);

        if ($mappings === [] || $start === null || $end === null) {
            return 0;
        }

        $hourly = self::hourlyPointsQuery($mappings, $start, $end, $dedupMeters, $timezone, $bbox);

        $row = DB::connection('clarity_clickhouse')
            ->query()
            ->fromSub($hourly, 'hourly')
            ->selectRaw('sum(length(points)) AS total_points')
            ->first();

        $total = $row['total_points'] ?? ($row->total_points ?? 0);

        return (int) $total;
    }

    /**
     * @return array{0: list<string>, 1: ?string, 2: ?string, 3: float, 4: string, 5: ?array{north: float, south: float, east: float, west: float}}
     */
    private static function resolveArguments(array $arguments): array
    {
        $mappings = array_values(array_unique(array_map(
            static fn ($mapping): string => strtolower((string) $mapping),
            $arguments['mappings'] ?? $arguments['_mappings'] ?? []
        )));

        $dedupMeters = (float) ($arguments['dedup_meters'] ?? self::DEFAULT_DEDUP_METERS);

        return [
            $mappings,
            $arguments['start'] ?? null,
            $arguments['end'] ?? null,
            min($dedupMeters, self::BREAK_METERS / 3),
            self::safeTimezone($arguments['timezone'] ?? 'UTC'),
            self::resolveOptionalBbox($arguments),
        ];
    }

    /**
     * @return array{north: float, south: float, east: float, west: float}|null
     */
    private static function resolveOptionalBbox(array $arguments): ?array
    {
        $north = $arguments['north'] ?? null;
        $south = $arguments['south'] ?? null;
        $east = $arguments['east'] ?? null;
        $west = $arguments['west'] ?? null;

        if ($north === null || $south === null || $east === null || $west === null) {
            return null;
        }

        return [
            'north' => (float) $north,
            'south' => (float) $south,
            'east' => (float) $east,
            'west' => (float) $west,
        ];
    }

    /**
     * Explode each per-(kit, hour) point array into its rendered segments,
     * splitting wherever consecutive points jump farther than the break
     * distance and dropping the <2-point remainders — arraySplit starts a new
     * chunk AT the breaking point, matching HistoricPathBuilder.
     *
     * @param  list<string>  $mappings  lowercased kit_mapping list
     * @param  array{north: float, south: float, east: float, west: float}|null  $bbox
     */
    private static function segmentsQuery(
        array $mappings,
        string $start,
        string $end,
        float $dedupMeters,
        string $timezone,
        ?array $bbox,
    ): Builder {
        $break = self::BREAK_METERS;
        $decimals = self::COORDINATE_DECIMALS;

        $splitExpression = <<<SQL
            arrayJoin(
                arrayFilter(
                    segment -> length(segment) >= 2,
                    arraySplit(
                        (point, index) -> index > 1 AND geoDistance(
                            points[index - 1].2, points[index - 1].3, point.2, point.3
                        ) > {$break},
                        points,
                        arrayEnumerate(points)
                    )
                )
            ) AS segment
            SQL;

        $exploded = DB::connection('clarity_clickhouse')
            ->query()
            ->fromSub(self::hourlyPointsQuery($mappings, $start, $end, $dedupMeters, $timezone, $bbox), 'hourly')
            ->selectRaw('mapping')
            ->selectRaw($splitExpression);

        return DB::connection('clarity_clickhouse')
            ->query()
            ->fromSub($exploded, 'exploded')
            ->selectRaw('mapping')
            ->selectRaw("toString(toDate(toDateTime(segment[1].1, 'UTC'), '{$timezone}')) AS trip_date")
            ->selectRaw("toJSONString(arrayMap(point -> [round(point.2, {$decimals}), round(point.3, {$decimals})], segment)) AS path_json")
            ->orderByRaw('mapping, segment[1].1');
    }

    /**
     * Shared per-(kit, hour) assembly, hour buckets in the viewer's timezone so
     * they line up with HistoricPathBuilder's local-hour split.
     * Array order matters: groupArray -> arraySort -> arrayFold (dedup) -> arraySlice (cap).
     *
     * @param  list<string>  $mappings  lowercased kit_mapping list
     * @param  array{north: float, south: float, east: float, west: float}|null  $bbox
     */
    private static function hourlyPointsQuery(
        array $mappings,
        string $start,
        string $end,
        float $dedupMeters,
        string $timezone,
        ?array $bbox = null,
    ): Builder {
        $placeholders = implode(',', array_fill(0, count($mappings), '?'));

        $decimated = DB::clickhouse('sc_positions')
            ->selectRaw('lower(kit_mapping) AS mapping')
            ->selectRaw('argMin(tupleElement(geo, 1), timestamp_utc) AS lng')
            ->selectRaw('argMin(tupleElement(geo, 2), timestamp_utc) AS lat')
            ->selectRaw('toString(min(timestamp_utc)) AS ts')
            ->whereRaw("lower(kit_mapping) IN ({$placeholders})", $mappings)
            ->whereRaw('timestamp_utc BETWEEN ? AND ?', [$start, $end])
            ->groupByRaw('lower(kit_mapping), toStartOfInterval(timestamp_utc, INTERVAL '.self::DECIMATION_SECONDS.' SECOND)');

        // Viewport pushdown (DEV-2374): filter source GPS before assembly so
        // Org Map historical only pays for what's on screen. Omitting all four
        // keeps Kit User Path / unbounded callers unchanged.
        if ($bbox !== null) {
            $decimated
                ->whereRaw('tupleElement(geo, 2) BETWEEN ? AND ?', [$bbox['south'], $bbox['north']])
                ->whereRaw('tupleElement(geo, 1) BETWEEN ? AND ?', [$bbox['west'], $bbox['east']]);
        }

        $cap = self::POINTS_PER_SEGMENT_CAP;
        $pointsExpression = <<<SQL
            arraySlice(
                arrayFold(
                    (acc, x) -> if(
                        length(acc.1) = 0 OR geoDistance(acc.2, acc.3, x.2, x.3) > {$dedupMeters},
                        (arrayPushBack(acc.1, x), x.2, x.3),
                        acc
                    ),
                    arraySort(x -> x.1, groupArray(tuple(ts, lng, lat))),
                    (CAST([], 'Array(Tuple(String, Float64, Float64))'), toFloat64(0), toFloat64(0))
                ).1,
                1, {$cap}
            ) AS points
            SQL;

        return DB::connection('clarity_clickhouse')
            ->query()
            ->fromSub($decimated, 'decimated')
            ->selectRaw('mapping')
            ->selectRaw($pointsExpression)
            ->groupByRaw("mapping, toStartOfHour(toDateTime(ts, 'UTC'), '{$timezone}')");
    }

    /**
     * Timezones are interpolated into raw SQL, so only a real IANA identifier
     * is ever allowed through.
     */
    private static function safeTimezone(string $timezone): string
    {
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
