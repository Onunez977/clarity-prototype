<?php

declare(strict_types=1);

namespace App\Services\Scout;

use App\Clickhouse\Queries\KitHistoricPositionsQuery;
use App\Dto\Scout\ShiftSummaryDto;
use App\Dto\Scout\ZoneSummaryDto;
use App\Models\Hit;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\KitShift;
use App\Models\User;
use App\Support\Scout\Haversine;
use App\Support\Scout\HistoricPathBuilder;
use App\Support\Scout\MapboxStaticMapBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assemble a {@see ShiftSummaryDto} for a closed shift.
 *
 * Data sources:
 *   - ClickHouse `clarity.sc_positions`    → scanner GPS track (for total miles & map segments),
 *                                            via {@see KitHistoricPositionsQuery} + {@see HistoricPathBuilder}
 *   - ClickHouse `clarity.scans_hot`/`cold`→ total scan count
 *   - ClickHouse `clarity.hits_cold`       → total hit count
 *   - PG `public.scans` ⋈ `platform.zones` (via ST_Contains) → per-zone breakdown
 *
 * Per-zone "time in zone" is computed by walking ordered scans for the shift
 * and attributing each delta to the zone containing the earlier scan in the
 * pair, capped at 30 minutes per delta so idle gaps aren't charged.
 *
 * Zones with zero scans are omitted from the per-zone list.
 */
class BuildShiftSummary
{
    private const TIME_IN_ZONE_CAP_MINUTES = 30;

    /**
     * Long-open shifts (never-closed kits) can span weeks. Summaries only
     * read the last N hours of ended_at so the zone CTE and hit lookup stay
     * workday-sized (DEV-2385). The DTO still reports the shift's real
     * started_at/ended_at and carries the read window for disclosure.
     */
    public const SUMMARY_WINDOW_HOURS = 8;

    public function __construct(
        private readonly MapboxStaticMapBuilder $mapBuilder = new MapboxStaticMapBuilder,
    ) {}

    public function forShift(KitShift $shift): ShiftSummaryDto
    {
        $kit = Kit::query()->find($shift->kit_id);
        $kitName = $kit?->name ?? 'Kit '.$shift->kit_id;

        [$driverName, $driverEmail] = $this->resolveDriver($shift->kit_id);

        // Resolve the read window once, in UTC. copy() avoids mutating the
        // shift's own Carbons (Carbon::utc() mutates in place), so the DTO/email
        // keep the shift's real timestamps. Every ClickHouse + PG read below
        // compares against UTC-stored data and shares this already-UTC window,
        // clamped to the last SUMMARY_WINDOW_HOURS (DEV-2385).
        [$windowStart, $endedAt] = $this->summaryWindow($shift);

        $positions = $this->pullPositions($kit?->kit_mapping, $windowStart, $endedAt);
        [$totalMiles, $tripSegments] = $this->summarizePositions($positions);

        $totalScans = $this->countScans($shift->org_id, $shift->kit_id, $windowStart, $endedAt);
        $totalHits = $this->countHits($shift->org_id, $shift->kit_id, $windowStart, $endedAt);

        $zoneSummaries = $this->buildZoneSummaries($shift, $windowStart, $endedAt);

        $zonePolygons = $this->loadZonePolygonsForMap($shift->org_id, array_map(fn ($z) => $z->id, $zoneSummaries));
        $mapUrl = $this->mapBuilder->build($tripSegments, $zonePolygons);

        return ShiftSummaryDto::forShift(
            shift: $shift,
            kitName: $kitName,
            driverName: $driverName,
            driverEmail: $driverEmail,
            totalMiles: round($totalMiles, 1),
            totalScans: $totalScans,
            totalHits: $totalHits,
            zones: $zoneSummaries,
            mapUrl: $mapUrl,
            windowStartedAt: $windowStart,
            windowEndedAt: $endedAt,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string} [name, email]
     */
    private function resolveDriver(int $kitId): array
    {
        $settings = KitSettings::query()->where('kit_id', $kitId)->first();

        if (! $settings || ! $settings->user_id) {
            return [null, null];
        }

        $user = User::query()->find($settings->user_id);

        return [$user?->name, $user?->email];
    }

    /**
     * @return array{0: Carbon, 1: Carbon} [windowStart, windowEnd] in UTC
     */
    private function summaryWindow(KitShift $shift): array
    {
        $endedAt = ($shift->ended_at ?? Carbon::now())->copy()->utc();
        $startedAt = $shift->started_at->copy()->utc();
        $windowStart = $endedAt->copy()->subHours(self::SUMMARY_WINDOW_HOURS);

        if ($startedAt->greaterThan($windowStart)) {
            $windowStart = $startedAt;
        }

        return [$windowStart, $endedAt];
    }

    /**
     * Raw scanner GPS positions for the shift window, decimated and ordered by
     * {@see KitHistoricPositionsQuery}. Empty when the kit has no mapping.
     *
     * @return list<array{lng: float, lat: float, timestamp_utc: string}>
     */
    protected function pullPositions(?string $kitMapping, Carbon $startedAt, Carbon $endedAt): array
    {
        if ($kitMapping === null || $kitMapping === '') {
            return [];
        }

        return KitHistoricPositionsQuery::handle([
            'kit_mapping' => $kitMapping,
            'start' => $startedAt->toDateTimeString(),
            'end' => $endedAt->toDateTimeString(),
            'timezone' => 'UTC',
        ]);
    }

    /**
     * Split the GPS track into gap-broken segments (a power-off jump breaks the
     * line, so no bogus connector is drawn or counted) and total their miles.
     *
     * @param  list<array{lng: float, lat: float, timestamp_utc: string}>  $positions
     * @return array{0: float, 1: list<list<array{0: float, 1: float}>>} [totalMiles, segments]
     */
    private function summarizePositions(array $positions): array
    {
        $totalMiles = 0.0;
        $segments = [];

        foreach (HistoricPathBuilder::build($positions, 'UTC') as $segment) {
            $segments[] = $segment['path'];
            $totalMiles += Haversine::miles($segment['path']);
        }

        return [$totalMiles, $segments];
    }

    protected function countScans(int $orgId, int $kitId, Carbon $startedAt, Carbon $endedAt): int
    {
        $startStr = $startedAt->toDateTimeString();
        $endStr = $endedAt->toDateTimeString();

        // Hot covers ~90 days; older shifts fall back to cold.
        $useHot = $startedAt->diffInDays(now()) <= 90;
        $table = $useHot ? 'scans_hot' : 'scans_cold';

        return (int) DB::clickhouse($table, final: true)
            ->where('org_id', $orgId)
            ->where('kit_id', $kitId)
            ->where('_peerdb_is_deleted', 0)
            ->where('scanned_at', '>=', $startStr)
            ->where('scanned_at', '<=', $endStr)
            ->count();
    }

    protected function countHits(int $orgId, int $kitId, Carbon $startedAt, Carbon $endedAt): int
    {
        $startStr = $startedAt->toDateTimeString();
        $endStr = $endedAt->toDateTimeString();

        // uniqExact(id), not count(): hits_cold holds duplicate rows per hit id that even
        // FINAL cannot collapse, because the sort key leads with the mutable
        // hotlist_org_id/hit_created_at, so a source-side created_at shift strands the
        // pre-shift row under a different key. Measured on prod 2026-08-24: 24,940,277 rows
        // for 18,805,223 distinct ids, and this query read 5,779 where the truth was 5,124.
        //
        // No FINAL here, unlike HitDensityGeohashQuery: none of these filter columns are
        // resolved differently by FINAL (measured: 0 of 4,105 kit_org_id-divergent duplicate
        // groups involve this org), so it returns the identical count for ~18x the bytes read.
        return (int) (DB::clickhouse('hits_cold')
            ->where('kit_id', $kitId)
            ->where('kit_org_id', $orgId)
            ->where('_peerdb_is_deleted', 0)
            ->where('hit_type', 'L')
            ->where('hit_created_at', '>=', $startStr)
            ->where('hit_created_at', '<=', $endStr)
            ->selectRaw('uniqExact(id) AS hits')
            ->value('hits') ?? 0);
    }

    /**
     * @return array<int, ZoneSummaryDto>
     */
    private function buildZoneSummaries(KitShift $shift, Carbon $startedAt, Carbon $endedAt): array
    {
        // One row per (scan, zone) pair, so a scan inside overlapping polygons counts toward each.
        //
        // The candidate scan set (kit_id + shift window) is computed in a
        // MATERIALIZED CTE so Postgres bounds it to this shift's handful of
        // rows BEFORE the ST_Contains spatial join runs — without the fence,
        // a zones/geo-GiST-driven plan can evaluate ST_Contains across an
        // unbounded slice of the giant partitioned public.scans table before
        // kit_id/scanned_at bind, which previously caused 57014 statement
        // timeouts (DEV-2325).
        $rows = DB::connection('platform')->select(
            '
            WITH shift_scans AS MATERIALIZED (
                SELECT id, scanned_at, geo FROM public.scans
                WHERE kit_id = ? AND scanned_at >= ? AND scanned_at <= ?
            )
            SELECT s.id AS scan_id, s.scanned_at AS scanned_at,
                   z.id AS zone_id, z.name AS zone_name, z.color AS zone_color
            FROM shift_scans s
            JOIN platform.zones z ON public.ST_Contains(z.geom, s.geo)
            WHERE z.org_id = ? AND z.deleted_at IS NULL
            ORDER BY s.scanned_at, z.id
            ',
            [$shift->kit_id, $startedAt->toDateTimeString(), $endedAt->toDateTimeString(), $shift->org_id]
        );

        if (empty($rows)) {
            return [];
        }

        /** @var array<int, array{name: string, color: ?string, scans: int, hits: int, minutes: int}> $zoneStats */
        $zoneStats = [];

        /** @var array<int, array<int, true>> $scanZoneMap  scan_id => [zone_id => true, ...] */
        $scanZoneMap = [];

        /** @var array<int, Carbon> $scanTimes  scan_id => Carbon */
        $scanTimes = [];

        /** @var array<int, int> $scanOrder  scan_id => order index */
        $scanOrder = [];

        $i = 0;
        foreach ($rows as $row) {
            $zid = (int) $row->zone_id;
            $sid = (int) $row->scan_id;
            $ts = Carbon::parse($row->scanned_at);

            $zoneStats[$zid] ??= [
                'name' => (string) $row->zone_name,
                'color' => $row->zone_color !== null ? (string) $row->zone_color : null,
                'scans' => 0,
                'hits' => 0,
                'minutes' => 0,
            ];

            $zoneStats[$zid]['scans']++;

            if (! isset($scanTimes[$sid])) {
                $scanTimes[$sid] = $ts;
                $scanOrder[$sid] = $i++;
            }
            $scanZoneMap[$sid][$zid] = true;
        }

        // Attribute each capped inter-scan delta to every zone containing the earlier scan.
        $orderedScanIds = array_keys($scanOrder);
        $count = count($orderedScanIds);
        for ($idx = 0; $idx < $count - 1; $idx++) {
            $thisSid = $orderedScanIds[$idx];
            $nextSid = $orderedScanIds[$idx + 1];

            // abs() guards Carbon 3's signed diffInMinutes against clock skew zeroing out the shift.
            $delta = (int) abs($scanTimes[$nextSid]->diffInMinutes($scanTimes[$thisSid]));
            $delta = min($delta, self::TIME_IN_ZONE_CAP_MINUTES);
            if ($delta <= 0) {
                continue;
            }

            foreach (array_keys($scanZoneMap[$thisSid]) as $zid) {
                $zoneStats[$zid]['minutes'] += $delta;
            }
        }

        $hits = $this->hitsForScanIds($orderedScanIds, $startedAt, $endedAt);

        foreach ($hits as $hit) {
            $sid = (int) $hit->scan_id;
            if (! isset($scanZoneMap[$sid])) {
                continue;
            }
            foreach (array_keys($scanZoneMap[$sid]) as $zid) {
                $zoneStats[$zid]['hits']++;
            }
        }

        $out = [];
        foreach ($zoneStats as $zid => $stats) {
            if ($stats['scans'] <= 0) {
                continue;
            }
            $out[] = new ZoneSummaryDto(
                id: $zid,
                name: $stats['name'],
                color: $stats['color'],
                scans: $stats['scans'],
                hits: $stats['hits'],
                timeInZoneMinutes: $stats['minutes'],
            );
        }

        usort($out, fn (ZoneSummaryDto $a, ZoneSummaryDto $b) => $b->scans <=> $a->scans);

        return $out;
    }

    /**
     * @param  list<int>  $orderedScanIds
     * @return Collection<int, Hit>
     */
    protected function hitsForScanIds(array $orderedScanIds, Carbon $startedAt, Carbon $endedAt): Collection
    {
        return Hit::query()
            ->select('scan_id')
            ->whereIntegerInRaw('scan_id', $orderedScanIds)
            ->where('hit_type', 'L')
            ->whereBetween('created_at', [$startedAt, $endedAt])
            ->get();
    }

    /**
     * @param  array<int, int>  $zoneIds
     * @return array<int, array{name: string, geojson: array<string, mixed>}>
     */
    private function loadZonePolygonsForMap(int $orgId, array $zoneIds): array
    {
        if (empty($zoneIds)) {
            return [];
        }

        // Simplify (0.001 ≈ 100m) before encoding to keep the Mapbox URL short.
        $rows = DB::connection('platform')->select(
            '
            SELECT id, name,
                   public.ST_AsGeoJSON(
                       public.ST_SimplifyPreserveTopology(geom, 0.001)
                   ) AS geom_geojson
            FROM platform.zones
            WHERE org_id = ?
              AND id = ANY(?::int[])
              AND deleted_at IS NULL
            ',
            [$orgId, '{'.implode(',', array_map('intval', $zoneIds)).'}']
        );

        $out = [];
        foreach ($rows as $row) {
            $geom = json_decode((string) $row->geom_geojson, true);
            if (! is_array($geom)) {
                continue;
            }
            $out[] = [
                'name' => (string) $row->name,
                'geojson' => $geom,
            ];
        }

        return $out;
    }
}
