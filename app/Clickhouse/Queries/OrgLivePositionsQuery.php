<?php

namespace App\Clickhouse\Queries;

use App\Http\Controllers\Api\ClarityScoutMapController;
use Illuminate\Support\Facades\DB;

/**
 * Latest raw scanner GPS fix (clarity.sc_positions) for EACH of an org's kit
 * mappings, in a single round trip — the org-wide analogue of
 * {@see ClarityScoutMapController::kitPosition()} that
 * powers Scout Bird's Eye's live driver dots (DEV-2116).
 *
 * sc_positions carries no org_id/kit_id — only kit_mapping — so the caller
 * resolves the org's mappings (lowercased) and passes them in; this query then
 * returns the most-recent point per mapping via argMax, keyed by the lowercased
 * mapping for the caller to join back to a kit. Heading is reported as-is; the
 * caller applies the "only when moving" gate, mirroring the single-kit reader.
 */
class OrgLivePositionsQuery extends ClickhouseQuery
{
    /**
     * @param  array{mappings?: list<string>, cutoff?: string}  $arguments
     * @return list<array{mapping: string, lng: float, lat: float, timestamp_utc: string, speed: float, heading: float}>
     */
    public static function handle(array $arguments = []): array
    {
        $mappings = array_values(array_unique(array_map(
            static fn ($m) => strtolower((string) $m),
            $arguments['mappings'] ?? []
        )));

        if ($mappings === []) {
            return [];
        }

        $cutoff = (string) ($arguments['cutoff'] ?? self::defaultCutoff());

        // Latest row per mapping in one pass. Group/select on lower(kit_mapping)
        // (CH `=`/`IN` are case-sensitive; platform lowercases, the scanner feed
        // does not — DEV-2012). The min/max-style argMax keeps lng+lat+speed+
        // heading on the same newest-in-group row. The timestamp is aliased `ts`,
        // NOT timestamp_utc: a `... AS timestamp_utc` alias shadows the real
        // DateTime column the aggregates reference (see KitHistoricPositionsQuery).
        $placeholders = implode(', ', array_fill(0, count($mappings), '?'));

        $rows = DB::clickhouse('sc_positions')
            ->selectRaw('lower(kit_mapping) AS mapping')
            ->selectRaw('argMax(tupleElement(geo, 1), timestamp_utc) AS lng')
            ->selectRaw('argMax(tupleElement(geo, 2), timestamp_utc) AS lat')
            ->selectRaw('toString(max(timestamp_utc)) AS ts')
            ->selectRaw('argMax(speed, timestamp_utc) AS speed')
            ->selectRaw('argMax(heading, timestamp_utc) AS heading')
            ->whereRaw("lower(kit_mapping) IN ({$placeholders})", $mappings)
            ->where('timestamp_utc', '>=', $cutoff)
            ->groupByRaw('lower(kit_mapping)')
            ->get();

        return array_map(static fn ($row) => [
            'mapping' => (string) ($row['mapping'] ?? $row->mapping),
            'lng' => (float) ($row['lng'] ?? $row->lng),
            'lat' => (float) ($row['lat'] ?? $row->lat),
            'timestamp_utc' => (string) ($row['ts'] ?? $row->ts),
            'speed' => (float) ($row['speed'] ?? $row->speed),
            'heading' => (float) ($row['heading'] ?? $row->heading),
        ], $rows->toArray());
    }

    /**
     * Default 24h lookback floor as a UTC datetime string. It is compared
     * against the UTC `timestamp_utc` column, so it must be built in UTC
     * regardless of APP_TIMEZONE (DEV-2164).
     */
    protected static function defaultCutoff(): string
    {
        return now()->utc()->subHours(24)->toDateTimeString();
    }
}
