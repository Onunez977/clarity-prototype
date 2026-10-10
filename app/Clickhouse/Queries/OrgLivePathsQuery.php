<?php

namespace App\Clickhouse\Queries;

use App\Support\Scout\HistoricPathBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raw scanner GPS positions (clarity.sc_positions) for MANY kit mappings at
 * once, decimated to one point per {@see self::DECIMATION_SECONDS}s and ordered
 * by time within each mapping — the org-wide analogue of
 * {@see KitHistoricPositionsQuery} that powers Scout Bird's Eye's per-driver
 * live trails (DEV-2116).
 *
 * Returns a flat, mapping-tagged point list; the caller groups by mapping and
 * feeds each kit's points to {@see HistoricPathBuilder} so a power-off gap
 * breaks a line rather than drawing a connector.
 */
class OrgLivePathsQuery extends ClickhouseQuery
{
    private const DECIMATION_SECONDS = 3;

    /**
     * Each mapping carries its OWN lower bound (`sinces`) so a fleet of drivers
     * whose fixes ingest at different latencies all advance independently — a
     * single shared cursor pinned to the freshest kit starves every laggier
     * driver's trail (their delayed points forever fall below the shared `since`).
     *
     * @param  array{sinces?: array<string, string>, end?: ?string, timezone?: string}  $arguments  sinces = lowercased mapping => UTC 'Y-m-d H:i:s' lower bound
     * @return list<array{mapping: string, lng: float, lat: float, timestamp_utc: string}>
     */
    public static function handle(array $arguments = []): array
    {
        $sinces = $arguments['sinces'] ?? [];
        $end = $arguments['end'] ?? null;
        $timezone = $arguments['timezone'] ?? 'UTC';

        if ($sinces === []) {
            return [];
        }

        // Per-mapping (lower(kit_mapping) = ? AND timestamp_utc >= that mapping's
        // cursor), OR'd together. Group on lower(kit_mapping) (case-sensitivity,
        // DEV-2012) plus the 3s decimation interval; argMin keeps both coords on
        // the earliest-in-bucket row (see KitHistoricPositionsQuery for the `ts`
        // alias / argMin rationale).
        $clauses = [];
        $bindings = [];

        foreach ($sinces as $mapping => $since) {
            $clauses[] = '(lower(kit_mapping) = ? AND timestamp_utc >= ?)';
            $bindings[] = strtolower((string) $mapping);
            $bindings[] = (string) $since;
        }

        $query = DB::clickhouse('sc_positions')
            ->selectRaw('lower(kit_mapping) AS mapping')
            ->selectRaw('argMin(tupleElement(geo, 1), timestamp_utc) AS lng')
            ->selectRaw('argMin(tupleElement(geo, 2), timestamp_utc) AS lat')
            ->selectRaw('toString(min(timestamp_utc)) AS ts')
            ->whereRaw('('.implode(' OR ', $clauses).')', $bindings)
            ->groupByRaw('lower(kit_mapping), toStartOfInterval(timestamp_utc, INTERVAL '.self::DECIMATION_SECONDS.' SECOND)')
            ->orderByRaw('lower(kit_mapping), min(timestamp_utc)');

        if ($end) {
            $query->where('timestamp_utc', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
        }

        return array_map(static fn ($row) => [
            'mapping' => (string) ($row['mapping'] ?? $row->mapping),
            'lng' => (float) ($row['lng'] ?? $row->lng),
            'lat' => (float) ($row['lat'] ?? $row->lat),
            'timestamp_utc' => (string) ($row['ts'] ?? $row->ts),
        ], $query->get()->toArray());
    }

    /**
     * Group a flat mapping-tagged point list into per-mapping segment arrays via
     * {@see HistoricPathBuilder}, ready for the client as `{mapping: segments[]}`.
     *
     * @param  list<array{mapping: string, lng: float, lat: float, timestamp_utc: string}>  $points
     * @return array<string, list<array{path: list<array{0: float, 1: float}>}>>
     */
    public static function segmentByMapping(array $points, string $timezone): array
    {
        $byMapping = [];

        foreach ($points as $point) {
            $byMapping[$point['mapping']][] = $point;
        }

        $segmentsByMapping = [];

        foreach ($byMapping as $mapping => $mappingPoints) {
            $segments = HistoricPathBuilder::build($mappingPoints, $timezone);
            $segmentsByMapping[$mapping] = array_map(
                static fn ($segment) => ['path' => $segment['path']],
                $segments,
            );
        }

        return $segmentsByMapping;
    }
}
