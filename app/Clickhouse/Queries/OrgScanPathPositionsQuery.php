<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Latest scan-derived position for each of an org's kits, in one round trip.
 *
 * Keyed by kit_id, not kit_mapping: kit_scan_paths has no kit_mapping column,
 * whereas {@see OrgLivePositionsQuery} must key on lower(kit_mapping) because
 * sc_positions has no org_id/kit_id. Don't conflate the two.
 */
class OrgScanPathPositionsQuery extends ClickhouseQuery
{
    /**
     * @param  array{org_id?: int, kit_ids?: list<int>, cutoff?: string}  $arguments
     * @return list<array{kit_id: int, lng: float, lat: float, timestamp_utc: string}>
     */
    public static function handle(array $arguments = []): array
    {
        $orgId = (int) ($arguments['org_id'] ?? 0);
        $kitIds = array_values(array_unique(array_map('intval', $arguments['kit_ids'] ?? [])));

        if ($orgId === 0 || $kitIds === []) {
            return [];
        }

        $cutoff = (string) ($arguments['cutoff'] ?? self::defaultCutoff());

        $rows = DB::clickhouse('kit_scan_paths', final: true)
            ->select(['kit_id'])
            // Aliased `ts`, not `timestamp`: that alias would shadow the column the aggregates read.
            ->selectRaw('argMax(tupleElement(arrayElement(path_points, length(path_points)), 1), timestamp) AS lng')
            ->selectRaw('argMax(tupleElement(arrayElement(path_points, length(path_points)), 2), timestamp) AS lat')
            ->selectRaw('toString(max(timestamp)) AS ts')
            ->where('org_id', $orgId)
            ->whereIntegerInRaw('kit_id', $kitIds)
            ->where('timestamp', '>=', $cutoff)
            ->whereRaw('length(path_points) >= 1')
            // Belt-and-braces: clarity.kit_scan_paths_mv (chlocal) already drops
            // zero-geo scans before grouping, so this cannot fire today — but that
            // producer is another repo's, and one (0,0) re-frames the whole map.
            ->whereRaw('tupleElement(arrayElement(path_points, length(path_points)), 1) != 0 AND tupleElement(arrayElement(path_points, length(path_points)), 2) != 0')
            ->groupBy('kit_id')
            ->get();

        return array_map(static fn ($row) => [
            'kit_id' => (int) ($row['kit_id'] ?? $row->kit_id),
            'lng' => (float) ($row['lng'] ?? $row->lng),
            'lat' => (float) ($row['lat'] ?? $row->lat),
            'timestamp_utc' => (string) ($row['ts'] ?? $row->ts),
        ], $rows->toArray());
    }

    /**
     * Compared against the UTC `timestamp` column, so it must be built in UTC
     * regardless of APP_TIMEZONE (DEV-2164).
     */
    protected static function defaultCutoff(): string
    {
        return now()->utc()->subHours(24)->toDateTimeString();
    }
}
