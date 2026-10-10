<?php

namespace App\Clickhouse\Queries;

use App\Support\Scout\HistoricPathBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raw scanner GPS positions (clarity.sc_positions) for one kit over a date
 * range, decimated to one point per {@see self::DECIMATION_SECONDS}s and ordered
 * by time. Feeds the Scout map's historic-path overlay via
 * {@see HistoricPathBuilder} (DEV-2018).
 *
 * The decimation interval is chosen so continuous driving — even at highway
 * speed — keeps consecutive kept points under the builder's 500ft break
 * threshold, so only genuine power-off gaps break a line.
 */
class KitHistoricPositionsQuery extends ClickhouseQuery
{
    private const DECIMATION_SECONDS = 3;

    /**
     * @return list<array{lng: float, lat: float, timestamp_utc: string}>
     */
    public static function handle(array $arguments = []): array
    {
        $mapping = (string) ($arguments['kit_mapping'] ?? '');
        $start = $arguments['start'] ?? null;
        $end = $arguments['end'] ?? null;
        $timezone = $arguments['timezone'] ?? 'UTC';
        $north = $arguments['north'] ?? null;
        $south = $arguments['south'] ?? null;
        $east = $arguments['east'] ?? null;
        $west = $arguments['west'] ?? null;

        if ($mapping === '') {
            return [];
        }

        // The min-timestamp output is aliased `ts`, NOT `timestamp_utc`: a
        // `... AS timestamp_utc` alias shadows the real DateTime column, so the
        // `timestamp_utc` referenced by toStartOfInterval/argMin would resolve
        // to that String alias and ClickHouse rejects it ("Illegal type String
        // of 1st argument of function toStartOfInterval"). argMin over the
        // extracted Float64 coordinates (rather than the geo Point) keeps both
        // coordinates on the same earliest-in-bucket row, tie-broken by
        // timestamp_utc (DEV-2018).
        $query = DB::clickhouse('sc_positions')
            ->selectRaw('argMin(tupleElement(geo, 1), timestamp_utc) AS lng')
            ->selectRaw('argMin(tupleElement(geo, 2), timestamp_utc) AS lat')
            ->selectRaw('toString(min(timestamp_utc)) AS ts')
            // Case-insensitive: platform lowercases kit_mapping, the scanner feed
            // keeps its own casing, and ClickHouse `=` is case-sensitive (DEV-2012).
            ->whereRaw('lower(kit_mapping) = ?', [strtolower($mapping)])
            ->groupByRaw('toStartOfInterval(timestamp_utc, INTERVAL '.self::DECIMATION_SECONDS.' SECOND)')
            ->orderByRaw('min(timestamp_utc)');

        if ($start) {
            $query->where('timestamp_utc', '>=', Carbon::parse($start, $timezone)->utc()->toDateTimeString());
        }

        if ($end) {
            $query->where('timestamp_utc', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
        }

        // Optional viewport bbox (DEV-2185): a plain lat/lng WHERE against the
        // extracted geo coordinates, additive to the kit/time scoping above so
        // omitting all four keeps today's rows unchanged.
        if ($north !== null && $south !== null && $east !== null && $west !== null) {
            $query->whereRaw('tupleElement(geo, 2) BETWEEN ? AND ?', [(float) $south, (float) $north]);
            $query->whereRaw('tupleElement(geo, 1) BETWEEN ? AND ?', [(float) $west, (float) $east]);
        }

        return array_map(static fn ($row) => [
            'lng' => (float) ($row['lng'] ?? $row->lng),
            'lat' => (float) ($row['lat'] ?? $row->lat),
            'timestamp_utc' => (string) ($row['ts'] ?? $row->ts),
        ], $query->get()->toArray());
    }
}
