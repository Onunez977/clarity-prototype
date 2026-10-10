<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use App\Console\Commands\ScoutRecordCheckIns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Matches a Scout kit's raw GPS fixes (clarity.sc_positions) against its org's
 * OPEN Direct + OPEN Staging order addresses, returning one row per distinct
 * (address geo, hour-bucket) pair — the automated check-in "events"
 * {@see ScoutRecordCheckIns} persists into `scout_check_ins`. A geo can carry
 * multiple address_ids (duplicate book rows, or genuinely distinct addresses
 * that happen to share a coordinate); one is elected per (geo, hour) PER
 * is_staged BUCKET — lowest address_id within the bucket — so one physical
 * visit produces one check-in per side regardless of how many address rows sit
 * on that geo.
 *
 * The bucket is in the partition key deliberately (DEV-2414). Electing a single
 * Direct-wins row across both sides would give a Staging order co-located with
 * a Direct one no row at all, ever — and DEV-1756's per-address count is derived
 * (`COUNT(*) ... WHERE address_id = ?`), so that address would read as never
 * visited. Measured across the 12 Scout-active orgs' own filtered views, geos
 * carrying both sides are ~0.27% of the geos an org sees (per-org range
 * 0.01%-0.78%), so keeping both costs ~0.27% of rows. That is a view-weighted
 * ratio, NOT a distinct fleet count: the views overlap heavily on shared staging
 * partners, so the underlying totals sum to ~3x the book and must not be quoted
 * as fleet-wide figures. The ratio is still the right basis here because
 * check-ins are generated per kit inside one org's view.
 *
 * What that does NOT cover, precisely: `min(is_staged)` in `address_hour` runs
 * BEFORE this partition, so a single address_id carried on BOTH a Direct and a
 * Staging hotlist collapses to one Direct row and never reaches the staging
 * bucket. Its staging-side coverage is still lost. Only genuinely DISTINCT
 * co-located address_ids are recovered here. That pre-existing per-address
 * Direct-wins is unchanged by DEV-2414, and it is why the grain is NOT identical
 * to {@see ScoutCheckInRollupQuery}: the rollup keeps such an address in both
 * buckets while this query emits one Direct row, and
 * `ScoutCheckInMatchQueryParityTest::test_shared_address_is_direct_wins_on_matcher_and_counted_in_both_rollup_buckets`
 * asserts exactly that surviving divergence.
 *
 * DEV-2426 closes the gap this paragraph used to document: `handle()` now
 * also returns the elected address's own coordinate (`addr_lng`/`addr_lat`,
 * below), and Postgres storage keys on `(kit_id, org_id, addr_lng,
 * addr_lat, hour_bucket, is_staged)` — the election's own grain, not the winning
 * `address_id`. A mid-hour winner change (a lower address_id opening at the
 * geo, or the current winner's order closing) now collides on that same key
 * and is correctly ignored, instead of adding a spurious second row.
 *
 * Three residual gaps remain (the first two accepted by explicit decision,
 * Dalton 2026-08-25; the third inherent to keying on a derived value):
 * - a geo carrying both a Direct and a Staging order still yields two rows
 *   for one visit, one per side — AC4's grain, deliberately preserved.
 * - `address_id`/`hotlist_id` are frozen at first sighting, so once an
 *   address_id's `is_staged` label differs between two 15-minute book
 *   rebuilds (temporal — not simultaneous dual-listing; 0 of 4,519,947 book
 *   address_ids carry both `is_staged` values, see
 *   docs/investigations/DEV-2426/flip-risk-measurement.md §3), both
 *   surviving rows can name the same address_id. DEV-1756's derived
 *   `COUNT(*) ... WHERE address_id = ?` can then report two check-ins for
 *   one address in one hour. Accepted, documented boundary.
 * - the elected GEO is itself mutable across book rebuilds — a
 *   re-geocoded address moves G -> G', so that also writes a second row for one visit. This
 *   mirrors the three mechanisms this ticket closes, is rarer than the order
 *   churn it replaces, and cannot be closed without a stable place id the book
 *   does not carry. Measured bound on the driver: 24.9% of zod.public_address
 *   rows have been updated since insert, ~166k/day synced. Detail in
 *   docs/investigations/DEV-2426/ac3-measurement-procedure.md.
 *
 * Election correctness depends on `geo` being functionally determined by
 * `address_id` within a book snapshot. That holds by construction: the book's
 * MV parses one coordinate per address id (chlocal migration 111 joins
 * zod.public_address to a per-id latest-version CTE), so every row for an
 * address_id carries the same geo whatever its hotlist_id.
 *
 * Deploy-boundary note: rows persisted before this change grouped on
 * address_id alone; there is no backfill, so a geo's history can straddle
 * both grains.
 *
 * Address source is clarity.scout_open_addresses, the fleet-wide open-order
 * book rebuilt every 15 minutes by clarity.scout_open_addresses_mv (chlocal
 * migration 111, DEV-2383). The book already applies status=Open,
 * length(plate) > 3, and the EWKT parse, so this class must not re-apply them.
 * Membership can be up to ~15 min plus rebuild time stale; chlocal owns
 * that rebuild. ORDER BY (org_id, is_staged, hotlist_id,
 * address_id) has no geo prefix, so the bbox prefilter is still load-bearing.
 *
 * The left side of the match is `clarity.sc_positions`, keyed by
 * `lower(kit_mapping)` — sc_positions has no org/kit id column, and the
 * platform lowercases kit_mapping while the scanner feed keeps its own
 * casing, so both sides are lower()'d (DEV-2012). Matching is two-stage:
 * `address_hour` first aggregates per (address_id, geo, hour) exactly as
 * before — an address_id on both a Direct and a Staging open order still
 * resolves Direct-wins-then-lowest-hotlist-id there — then `ranked` elects
 * one address_id per (geo, hour, is_staged) by lowest address_id within that
 * bucket. The earliest fix supplies `matched_at`/`lat`/`lng` — via a single
 * tuple argMin on timestamp_utc, so two fixes sharing the same second can
 * never blend lng from one row with lat from another. `addr_lng`/`addr_lat`
 * are a SEPARATE pair — the elected book ADDRESS's own coordinate, computed
 * in `addresses` and carried through unchanged (it is what `ranked`
 * partitions on). Do not conflate the two: `lat`/`lng` move with the kit's
 * GPS fix; `addr_lat`/`addr_lng` are fixed to the book row.
 * {@see ScoutCheckInRollupQuery} is a dashboard count tile with its own
 * per-bucket dedup and counts a shared geo in both the Direct and Staging
 * buckets.
 *
 * Coordinates come from tupleElement(geo, 1)/(geo, 2) — the book stores a
 * ClickHouse Point, the same shape as sc_positions. No FINAL (plain MergeTree,
 * one row per (hotlist_id, address_id) per rebuild).
 *
 * Performance: a busy kit's org can have ~17k open-assignment addresses, so
 * the candidate address set is bbox-prefiltered to the kit's own position
 * window (min/max lng/lat, padded by BBOX_PAD_DEGREES) before the geoDistance
 * join — identical rationale to ScoutCheckInRollupQuery. The bbox is a
 * CROSS JOIN against the CTE, not four scalar subqueries.
 */
class ScoutCheckInMatchQuery extends ClickhouseQuery
{
    private const PROXIMITY_METERS = 160.934;

    /**
     * See {@see ScoutCheckInRollupQuery::BBOX_PAD_DEGREES} for the sizing
     * rationale — kept identical here.
     */
    private const BBOX_PAD_DEGREES = 0.003;

    /**
     * @param  array{kit_mapping: string, org_id: int, staging_org_ids: list<int>, start: string, end: string}  $arguments
     * @return list<array{address_id: int, hotlist_id: int, is_staged: bool, hour_bucket: string, matched_at: string, lat: float, lng: float, addr_lat: float, addr_lng: float}>
     */
    public static function handle(array $arguments = []): array
    {
        $mapping = strtolower((string) $arguments['kit_mapping']);

        if ($mapping === '') {
            return [];
        }

        $orgId = (int) $arguments['org_id'];
        $stagingOrgIds = array_values(array_unique(array_map(
            'intval',
            $arguments['staging_org_ids'] ?? []
        )));
        $start = Carbon::parse($arguments['start'])->utc()->toDateTimeString();
        $end = Carbon::parse($arguments['end'])->utc()->toDateTimeString();

        $proximity = self::PROXIMITY_METERS;
        $pad = self::BBOX_PAD_DEGREES;
        $db = self::clarityDb();
        $mappingLiteral = addslashes($mapping);
        $hotlistOrgFilter = self::hotlistOrgFilter($orgId, $stagingOrgIds);

        $sql = <<<SQL
            WITH position_window AS (
                SELECT
                    timestamp_utc,
                    tupleElement(geo, 1) AS lng,
                    tupleElement(geo, 2) AS lat,
                    toStartOfHour(timestamp_utc) AS hour_bucket
                FROM {$db}.sc_positions
                WHERE lower(kit_mapping) = '{$mappingLiteral}'
                  AND timestamp_utc >= '{$start}'
                  AND timestamp_utc <= '{$end}'
                  AND tupleElement(geo, 1) != 0
                  AND tupleElement(geo, 2) != 0
            ),
            position_bbox AS (
                SELECT
                    min(lng) - {$pad} AS min_lng,
                    max(lng) + {$pad} AS max_lng,
                    min(lat) - {$pad} AS min_lat,
                    max(lat) + {$pad} AS max_lat
                FROM position_window
            ),
            addresses AS (
                SELECT
                    book.address_id AS address_id,
                    book.hotlist_id AS hotlist_id,
                    book.is_staged AS is_staged,
                    tupleElement(book.geo, 1) AS addr_lng,
                    tupleElement(book.geo, 2) AS addr_lat
                FROM {$db}.scout_open_addresses book
                CROSS JOIN position_bbox b
                WHERE ({$hotlistOrgFilter})
                  AND tupleElement(book.geo, 1) != 0
                  AND tupleElement(book.geo, 2) != 0
                  AND tupleElement(book.geo, 1) BETWEEN b.min_lng AND b.max_lng
                  AND tupleElement(book.geo, 2) BETWEEN b.min_lat AND b.max_lat
            ),
            matches AS (
                SELECT
                    a.address_id AS address_id,
                    a.hotlist_id AS hotlist_id,
                    a.is_staged AS is_staged,
                    a.addr_lng AS addr_lng,
                    a.addr_lat AS addr_lat,
                    p.timestamp_utc AS timestamp_utc,
                    p.hour_bucket AS hour_bucket,
                    p.lng AS lng,
                    p.lat AS lat
                FROM position_window p
                INNER JOIN addresses a
                    ON geoDistance(p.lng, p.lat, a.addr_lng, a.addr_lat) <= {$proximity}
            ),
            address_hour AS (
                SELECT
                    address_id,
                    addr_lng,
                    addr_lat,
                    hour_bucket,
                    argMin(hotlist_id, (toUInt8(is_staged), hotlist_id)) AS out_hotlist_id,
                    min(is_staged) AS out_is_staged,
                    tupleElement(argMin((lng, lat), timestamp_utc), 1) AS out_lng,
                    tupleElement(argMin((lng, lat), timestamp_utc), 2) AS out_lat,
                    min(timestamp_utc) AS out_matched_at
                FROM matches
                GROUP BY address_id, addr_lng, addr_lat, hour_bucket
            ),
            ranked AS (
                SELECT *, ROW_NUMBER() OVER (
                    PARTITION BY addr_lng, addr_lat, hour_bucket, out_is_staged
                    ORDER BY address_id ASC
                ) AS rn
                FROM address_hour
            )
            SELECT
                address_id AS out_address_id,
                out_hotlist_id AS out_hotlist_id,
                out_is_staged AS out_is_staged,
                toString(hour_bucket) AS out_hour_bucket,
                out_lng AS out_lng,
                out_lat AS out_lat,
                addr_lng AS out_addr_lng,
                addr_lat AS out_addr_lat,
                toString(out_matched_at) AS out_matched_at
            FROM ranked
            WHERE rn = 1
        SQL;

        $rows = DB::connection('clarity_clickhouse')->select($sql);

        return array_map(static fn ($row) => [
            'address_id' => (int) ($row['out_address_id'] ?? $row->out_address_id),
            'hotlist_id' => (int) ($row['out_hotlist_id'] ?? $row->out_hotlist_id),
            'is_staged' => (bool) ($row['out_is_staged'] ?? $row->out_is_staged),
            'hour_bucket' => (string) ($row['out_hour_bucket'] ?? $row->out_hour_bucket),
            'matched_at' => (string) ($row['out_matched_at'] ?? $row->out_matched_at),
            'lat' => (float) ($row['out_lat'] ?? $row->out_lat),
            'lng' => (float) ($row['out_lng'] ?? $row->out_lng),
            'addr_lat' => (float) ($row['out_addr_lat'] ?? $row->out_addr_lat),
            'addr_lng' => (float) ($row['out_addr_lng'] ?? $row->out_addr_lng),
        ], $rows);
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
