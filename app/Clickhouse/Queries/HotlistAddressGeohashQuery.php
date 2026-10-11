<?php

namespace App\Clickhouse\Queries;

use App\Models\Org;
use Illuminate\Support\Facades\DB;

class HotlistAddressGeohashQuery extends ClickhouseQuery
{
    public static function handle(array $arguments = []): array
    {
        $orgId = $arguments['org_id'];
        $staging = $arguments['staging'] ?? false;
        $tiers = $arguments['tiers'] ?? [];

        if (empty($tiers)) {
            return [];
        }

        $north = $arguments['north'] ?? null;
        $south = $arguments['south'] ?? null;
        $east = $arguments['east'] ?? null;
        $west = $arguments['west'] ?? null;
        $precision = self::sanitizePrecision($arguments['precision'] ?? 6);

        $runAreaRings = $arguments['_run_area_rings'] ?? null;

        $resolvedOrgIds = $staging
            ? (Org::find($orgId)?->stagingOrgIds()?->toArray() ?? [])
            : [$orgId];

        if (empty($resolvedOrgIds)) {
            return [];
        }

        // Every precision computes the geohash dynamically from raw address rows
        // (single data source). The precision-6 materialized view diverged from
        // this finer raw path on status/plate/0,0 filtering, so it was retired.
        return self::queryClickhouseRaw(
            orgIds: $resolvedOrgIds,
            precision: $precision,
            north: $north,
            south: $south,
            east: $east,
            west: $west,
            tiers: $tiers,
            runAreaRings: is_array($runAreaRings) ? $runAreaRings : null,
            staging: $staging,
        );
    }

    /**
     * @param  list<list<array{0: int|float, 1: int|float}>>  $rings
     */
    private static function runAreaClause(string $lng, string $lat, array $rings): string
    {
        $clauses = array_map(
            fn (array $ring) => 'pointInPolygon(('.$lng.', '.$lat.'), '.self::polygonLiteral($ring).')',
            $rings,
        );

        return '('.implode(' OR ', $clauses).')';
    }

    /**
     * Dynamic-precision order density straight from the raw CDC tables in the
     * zod ClickHouse database. Mirrors the precision-6 MV (org-scoped + bbox +
     * run-area clip + address-count tiers) but encodes the geohash at the
     * requested precision so the cells resize. Runs on the clarity_clickhouse
     * connection (which carries validate_polygons=0 for the run-area clip) and
     * reaches the zod.* tables cross-database, mirroring the status-log queries.
     *
     * @param  list<int>  $orgIds
     * @param  list<string>  $tiers
     * @param  list<list<array{0: int|float, 1: int|float}>>|null  $runAreaRings
     */
    private static function queryClickhouseRaw(
        array $orgIds,
        int $precision,
        ?float $north,
        ?float $south,
        ?float $east,
        ?float $west,
        array $tiers,
        ?array $runAreaRings = null,
        bool $staging = false,
    ): array {
        $orgList = implode(',', array_map('intval', $orgIds));

        $clarityDb = self::clarityDb();
        $zodDb = self::zodDb();

        $bbox = '';
        if ($north !== null && $south !== null) {
            $bbox .= ' AND lat BETWEEN '.((float) $south).' AND '.((float) $north);
        }
        if ($east !== null && $west !== null) {
            $bbox .= ' AND lng BETWEEN '.((float) $west).' AND '.((float) $east);
        }

        // Run-area clip on the decoded point. validate_polygons=0 (set on the
        // clarity_clickhouse connection) lets the non-simple TIGER rings through
        // pointInPolygon without throwing — same as the MV path.
        // assumeNotNull: lng/lat are Nullable (toFloat64OrNull) and pointInPolygon
        // rejects a nullable tuple even though the outer WHERE already drops nulls.
        $runArea = '';
        if (is_array($runAreaRings) && $runAreaRings !== []) {
            $runArea = ' AND '.self::runAreaClause('assumeNotNull(lng)', 'assumeNotNull(lat)', $runAreaRings);
        }

        $having = '';
        $tierConditions = self::tierConditions($tiers);
        if (! empty($tierConditions)) {
            $having = 'HAVING ('.implode(' OR ', $tierConditions).')';
        }

        // Canonical staging order-addresses require is_staged = true — forwarder orgs
        // (resolved into $orgIds via stagingOrgIds) don't stage every order, so without
        // this the staging layer over-counts (DEV-2080, audit finding A). The direct
        // layer needs no is_staged filter: org_id already scopes to the org's own orders.
        $stagedFilter = $staging ? ' AND is_staged = true' : '';

        // Plate guard `length(plate) >= 3`: drop orders with no actionable plate,
        // matching the dashboard-stats rule (HotlistCount). DEV-2183.

        // Resolve the org's addresses by id-membership rather than a join: a JOIN
        // hashes the 100M+ public_address table (right side) and times out, while
        // `a.id IN (...)` rides the table's id primary key. geo is EWKT
        // (`SRID=4326;POINT (lng lat)`) — extract coords with re2 rather than
        // readWKTPoint, which throws on a malformed/non-POINT row (ClickHouse then
        // swallows it into an empty layer); toFloat64OrNull yields NULL for bad
        // rows, which the WHERE drops.
        $sql = <<<SQL
            SELECT geohashEncode(lng, lat, {$precision}) AS geohash,
                   count(DISTINCT lat, lng) AS address_count
            FROM (
                SELECT a.id AS a_id,
                       toFloat64OrNull(extract(a.geo, 'POINT ?\\(([-0-9.]+) ')) AS lng,
                       toFloat64OrNull(extract(a.geo, ' ([-0-9.]+)\\)')) AS lat
                FROM {$zodDb}.public_address a
                WHERE a._peerdb_is_deleted = 0
                  AND (a.geo != '')
                  AND (a.geo IS NOT NULL)
                  AND (a.house_number IS NOT NULL)
                  AND (a.house_number != '')
                  AND (a.road IS NOT NULL)
                  AND (a.road != '')
                  AND a.id IN (
                      SELECT ha.address_id
                      FROM {$zodDb}.public_hotlist_address ha
                      WHERE ha.hotlist_id IN (
                          SELECT id FROM {$clarityDb}.hotlist FINAL
                          WHERE _peerdb_is_deleted = 0
                            AND org_id IN ({$orgList})
                            AND status = 1
                            AND length(plate) >= 3{$stagedFilter}
                      )
                  )
            )
            WHERE lng IS NOT NULL AND lat IS NOT NULL{$bbox}{$runArea}
            GROUP BY geohash
            {$having}
        SQL;

        return DB::connection('clarity_clickhouse')->select($sql);
    }

    /**
     * @param  list<string>  $tiers
     * @return list<string>
     */
    private static function tierConditions(array $tiers, string $column = 'address_count'): array
    {
        return array_values(array_filter(array_map(fn ($tier) => match ($tier) {
            'tier_1' => "$column = 1",
            'tier_2_4' => "($column >= 2 AND $column <= 4)",
            'tier_5_9' => "($column >= 5 AND $column <= 9)",
            'tier_10_24' => "($column >= 10 AND $column <= 24)",
            'tier_25_plus' => "$column >= 25",
            default => null,
        }, $tiers)));
    }

    private static function sanitizePrecision(mixed $precision): int
    {
        $precision = (int) $precision;

        return $precision >= 6 && $precision <= 9 ? $precision : 6;
    }

    public static function getTier(int $count): string
    {
        return match (true) {
            $count >= 25 => 'tier_25_plus',
            $count >= 10 => 'tier_10_24',
            $count >= 5 => 'tier_5_9',
            $count >= 2 => 'tier_2_4',
            default => 'tier_1',
        };
    }

    public static function getTierColor(int $count, bool $staging = false): string
    {
        if ($staging) {
            return match (true) {
                $count >= 25 => '#1B5E20',
                $count >= 10 => '#2E7D32',
                $count >= 5 => '#43A047',
                $count >= 2 => '#81C784',
                default => '#C8E6C9',
            };
        }

        return match (true) {
            $count >= 25 => '#4A148C',
            $count >= 10 => '#7B1FA2',
            $count >= 5 => '#AB47BC',
            $count >= 2 => '#CE93D8',
            default => '#E1BEE7',
        };
    }

    /**
     * Tier band for the unioned Direct + Staging order layers (DEV-2600). Once the
     * layers merge into a single shaded cell the per-source purple/green ramps no
     * longer apply; this is the five-band Kit scale on one unioned Direct+Staging layer.
     */
    public static function getCombinedTier(int $count): string
    {
        return match (true) {
            $count >= 25 => 'tier_25_plus',
            $count >= 10 => 'tier_10_24',
            $count >= 5 => 'tier_5_9',
            $count >= 2 => 'tier_2_4',
            default => 'tier_1',
        };
    }

    public static function getCombinedTierColor(int $count): string
    {
        return match (true) {
            $count >= 25 => '#4A148C',
            $count >= 10 => '#7B1FA2',
            $count >= 5 => '#AB47BC',
            $count >= 2 => '#CE93D8',
            default => '#E1BEE7',
        };
    }

    public static function getCombinedTierLabel(int $count): string
    {
        return match (self::getCombinedTier($count)) {
            'tier_25_plus' => '25+ addresses',
            'tier_10_24' => '10-24 addresses',
            'tier_5_9' => '5-9 addresses',
            'tier_2_4' => '2-4 addresses',
            default => '1 address',
        };
    }
}
