<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use App\Enums\DispositionEnum;
use App\Enums\HotlistStatusEnum;
use Illuminate\Support\Facades\DB;

/**
 * Direct Hit Hotspots (DEV-2030).
 *
 * Aggregates `hits_cold` into geohash cells scoped to the org's CURRENTLY-open
 * orders via a semi-join to `hotlist` (FINAL) — not the hit row's denormalized
 * `hotlist_status`, which is frozen at write time and never back-updated, so an
 * order repossessed/closed after its hit would otherwise keep shading the cell
 * (DEV-2117, mirroring the DEV-2113 marker fix). Repeat scans of the same plate
 * dedup to one per (hour, ~38m cell) so a parked car can't inflate a hotspot.
 */
class HitDirectHotspotQuery extends ClickhouseQuery
{
    private const OPEN_STATUS = HotlistStatusEnum::Open->value;

    /**
     * Inner dedup resolution — geohash precision 8 (~38m). Repeat hits within
     * one cell in the same hour collapse to a single count.
     */
    private const DEDUP_PRECISION = 8;

    public static function handle(array $arguments = []): array
    {
        $orgId = (int) $arguments['org_id'];

        $north = $arguments['north'] ?? null;
        $south = $arguments['south'] ?? null;
        $east = $arguments['east'] ?? null;
        $west = $arguments['west'] ?? null;
        $precision = self::sanitizePrecision($arguments['precision'] ?? 6);

        $cutoff = now()->subMonths(9)->startOfDay()->utc()->toDateTimeString();

        $db = self::clarityDb();
        $openStatus = self::OPEN_STATUS;
        $dedupPrecision = self::DEDUP_PRECISION;
        $valid = DispositionEnum::VALID->value;

        $bbox = '';
        if ($north !== null && $south !== null) {
            $bbox .= ' AND h.lat BETWEEN '.((float) $south).' AND '.((float) $north);
        }
        if ($east !== null && $west !== null) {
            $bbox .= ' AND h.lng BETWEEN '.((float) $west).' AND '.((float) $east);
        }

        $deduped = "
            SELECT
                toStartOfHour(h.hit_created_at) AS hour_bucket,
                geohashEncode(h.lng, h.lat, {$dedupPrecision}) AS loc_bucket,
                any(h.lng) AS lng,
                any(h.lat) AS lat
            FROM {$db}.hits_cold h FINAL
            WHERE h.hotlist_org_id = {$orgId}
              AND h.hotlist_id IN (
                  SELECT id FROM {$db}.hotlist FINAL
                  WHERE org_id = {$orgId}
                    AND status = {$openStatus}
                    AND _peerdb_is_deleted = 0
              )
              AND h._peerdb_is_deleted = 0
              AND h.hit_type = 'L'
              AND h.hit_created_at >= '{$cutoff}'
              AND h.lat != 0 AND h.lng != 0
              AND (h.disposition IS NULL OR h.disposition = '{$valid}')
              {$bbox}
            GROUP BY hour_bucket, loc_bucket
        ";

        $sql = "
            SELECT geohashEncode(lng, lat, {$precision}) AS geohash, count() AS hit_count
            FROM ({$deduped}) deduped
            GROUP BY geohash
        ";

        return DB::connection('clarity_clickhouse')->select($sql);
    }

    private static function sanitizePrecision(mixed $precision): int
    {
        $precision = (int) $precision;

        return $precision >= 6 && $precision <= 9 ? $precision : 6;
    }

    public static function getTier(int $count): string
    {
        return match (true) {
            $count >= 5 => 'hit_tier_5_plus',
            $count >= 2 => 'hit_tier_2_4',
            default => 'hit_tier_1',
        };
    }

    /**
     * Warning ramp (green = low, red = high): a lone hit is calm green, a
     * dense cluster is alarming red.
     */
    public static function getTierColor(int $count): string
    {
        return match (self::getTier($count)) {
            'hit_tier_5_plus' => '#EF4444',
            'hit_tier_2_4' => '#EAB308',
            default => '#22C55E',
        };
    }

    public static function getTierLabel(int $count): string
    {
        return match (self::getTier($count)) {
            'hit_tier_5_plus' => '5+ hits',
            'hit_tier_2_4' => '2-4 hits',
            default => '1 hit',
        };
    }
}
