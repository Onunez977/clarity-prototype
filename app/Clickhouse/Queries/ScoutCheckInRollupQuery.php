<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Counts automated check-ins for a Scout kit user, split by order side
 * (Direct vs Staging).
 *
 * A check-in is one (address geo, hour-bucket) pair where the kit's scan
 * landed within ~160m (0.1 mi) of an open-hotlist address; addresses sharing
 * a geo collapse to one count per bucket. The matcher shape mirrors
 * LprVisitCountQuery so the two surfaces stay consistent.
 *
 * Address source is clarity.scout_open_addresses, the fleet-wide open-order
 * book rebuilt every 15 minutes by clarity.scout_open_addresses_mv (chlocal
 * migration 111, DEV-2383). The book already applies status=Open,
 * length(plate) > 3, and the EWKT parse. Coordinates come from
 * tupleElement(geo, 1)/(geo, 2). No FINAL (plain MergeTree).
 *
 * Performance: a busy kit's org can have ~17k open-assignment addresses, and
 * a full scans x addresses geoDistance cross-join exceeds ClickHouse's read
 * budget. We prefilter the candidate addresses to a bounding box derived from
 * the kit's own scan window (min/max lng/lat, padded by BBOX_PAD_DEGREES >
 * the proximity radius) BEFORE the geoDistance join, via a CROSS JOIN of the
 * bbox CTE rather than four scalar subqueries. A silent failure here degrades
 * to {0,0} (ClickhouseQuery swallows exceptions).
 *
 * Inner CTE returns per-match rows tagged with `is_staged`; `ranked`
 * dedups per (is_staged, geo, hour) so addresses sharing a geo count once per
 * bucket, keeping `is_staged` in the partition key so a geo shared by a
 * Direct and a Staging order still counts once in EACH bucket — no
 * Direct-wins here, unlike {@see ScoutCheckInMatchQuery}'s persisted row.
 *
 * Database prefixes are resolved via self::clarityDb(), not hardcoded, so
 * this also runs correctly against a worktree-suffixed local CH database.
 */
class ScoutCheckInRollupQuery extends ClickhouseQuery
{
    private const PROXIMITY_METERS = 160.934;

    /**
     * Degrees of padding added around the kit's scan-window bounding box
     * before the geoDistance join. 0.003 deg is the tighter longitude axis:
     * ~333m in latitude, and ~0.003*111320*cos(lat) in longitude -- ~219m at
     * 49N (the northern edge of the lower 48), still comfortably wider than
     * PROXIMITY_METERS (160.9m), so no address within the check-in radius of an
     * edge scan is dropped across the continental-US operating area. (It thins
     * toward the radius only above ~61N; revisit as a meters-derived per-axis
     * pad if Scout operates in interior Alaska.)
     */
    private const BBOX_PAD_DEGREES = 0.003;

    public static function handle(array $arguments = []): array
    {
        $orgId = (int) $arguments['org_id'];
        $kitId = (int) $arguments['kit_id'];
        $stagingOrgIds = array_values(array_unique(array_map(
            'intval',
            $arguments['staging_org_ids'] ?? []
        )));
        $start = Carbon::parse($arguments['start'])->utc()->toDateTimeString();
        $end = Carbon::parse($arguments['end'])->utc()->toDateTimeString();

        $scansTable = self::scansTable($arguments['start']);
        $hotlistOrgFilter = self::hotlistOrgFilter($orgId, $stagingOrgIds);
        $proximity = self::PROXIMITY_METERS;
        $pad = self::BBOX_PAD_DEGREES;
        $db = self::clarityDb();

        $sql = <<<SQL
            WITH scan_window AS (
                SELECT lng, lat, toStartOfHour(scanned_at) AS hour_bucket
                FROM {$db}.{$scansTable} FINAL
                WHERE org_id = {$orgId}
                  AND kit_id = {$kitId}
                  AND scanned_at >= '{$start}'
                  AND scanned_at <= '{$end}'
                  AND lng != 0 AND lat != 0
                  AND _peerdb_is_deleted = 0
            ),
            scan_bbox AS (
                SELECT
                    min(lng) - {$pad} AS min_lng,
                    max(lng) + {$pad} AS max_lng,
                    min(lat) - {$pad} AS min_lat,
                    max(lat) + {$pad} AS max_lat
                FROM scan_window
            ),
            addresses AS (
                SELECT DISTINCT
                    book.address_id AS address_id,
                    book.is_staged AS is_staged,
                    tupleElement(book.geo, 1) AS addr_lng,
                    tupleElement(book.geo, 2) AS addr_lat
                FROM {$db}.scout_open_addresses book
                CROSS JOIN scan_bbox b
                WHERE ({$hotlistOrgFilter})
                  AND tupleElement(book.geo, 1) != 0
                  AND tupleElement(book.geo, 2) != 0
                  AND tupleElement(book.geo, 1) BETWEEN b.min_lng AND b.max_lng
                  AND tupleElement(book.geo, 2) BETWEEN b.min_lat AND b.max_lat
            ),
            matches AS (
                SELECT DISTINCT
                    a.address_id AS address_id,
                    a.is_staged AS is_staged,
                    a.addr_lng AS addr_lng,
                    a.addr_lat AS addr_lat,
                    s.hour_bucket AS hour_bucket
                FROM scan_window s
                INNER JOIN addresses a
                    ON geoDistance(s.lng, s.lat, a.addr_lng, a.addr_lat) <= {$proximity}
            ),
            ranked AS (
                SELECT *, ROW_NUMBER() OVER (
                    PARTITION BY is_staged, addr_lng, addr_lat, hour_bucket
                    ORDER BY address_id ASC
                ) AS rn
                FROM matches
            )
            SELECT
                toUInt64(countIf(is_staged = false AND rn = 1)) AS direct_bucket,
                toUInt64(countIf(is_staged = true AND rn = 1)) AS staging_bucket
            FROM ranked
        SQL;

        $row = DB::connection('clarity_clickhouse')->select($sql);

        return [
            'direct_bucket' => (int) ($row[0]['direct_bucket'] ?? 0),
            'staging_bucket' => (int) ($row[0]['staging_bucket'] ?? 0),
        ];
    }

    private static function scansTable(string $start): string
    {
        return Carbon::parse($start)->diffInDays(now()) <= 90
            ? 'scans_hot'
            : 'scans_cold';
    }

    /**
     * @param  array<int>  $stagingOrgIds
     */
    private static function hotlistOrgFilter(int $orgId, array $stagingOrgIds): string
    {
        $direct = "(org_id = {$orgId} AND is_staged = false)";

        if (empty($stagingOrgIds)) {
            return $direct;
        }

        $stagingList = implode(',', $stagingOrgIds);

        return "{$direct} OR (org_id IN ({$stagingList}) AND is_staged = true)";
    }
}
