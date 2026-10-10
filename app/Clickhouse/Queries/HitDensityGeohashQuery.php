<?php

namespace App\Clickhouse\Queries;

use App\Enums\DispositionEnum;
use Illuminate\Support\Facades\DB;

class HitDensityGeohashQuery extends ClickhouseQuery
{
    public const PIN_LIMIT = 5000;

    public static function handle(array $arguments = []): array
    {
        $orgId = $arguments['org_id'];
        $tiers = $arguments['tiers'] ?? [];

        if (empty($tiers)) {
            return [];
        }

        $north = $arguments['north'] ?? null;
        $south = $arguments['south'] ?? null;
        $east = $arguments['east'] ?? null;
        $west = $arguments['west'] ?? null;
        $precision = self::sanitizePrecision($arguments['precision'] ?? 6);
        $mode = ($arguments['mode'] ?? 'aggregate') === 'pins' ? 'pins' : 'aggregate';

        $cutoff = now()->subMonths(9)->startOfDay()->utc()->toDateTimeString();

        // FINAL is required here, not just uniqExact(id) below. `disposition` is not in
        // hits_cold's sort key, so without FINAL a stale NULL-disposition row keeps a hit
        // visible even after it has been dispositioned away. Measured for org 170: 236 hits
        // across 189 geohash cells that are now "Invalid Scan"/"Moving"/etc. still passed
        // the filter. FINAL costs ~36% more bytes read here and resolves them correctly.
        $query = DB::clickhouse('hits_cold', final: true)
            ->where('hotlist_org_id', $orgId)
            ->where('_peerdb_is_deleted', 0)
            ->whereIn('hit_type', $arguments['hit_types'] ?? ['L', 'H', 'S', 'Z'])
            ->whereRaw("hit_created_at >= '".addslashes($cutoff)."'")
            ->whereRaw('lat != 0 AND lng != 0')
            ->whereRaw("(disposition IS NULL OR disposition = '".addslashes(DispositionEnum::VALID->value)."')");

        if ($north !== null && $south !== null) {
            $query->whereRaw('lat BETWEEN '.((float) $south).' AND '.((float) $north));
        }

        if ($east !== null && $west !== null) {
            $query->whereRaw('lng BETWEEN '.((float) $west).' AND '.((float) $east));
        }

        if ($mode === 'pins') {
            return $query
                ->selectRaw('lat, lng')
                ->limit(self::PIN_LIMIT + 1)
                ->get()
                ->toArray();
        }

        $query
            ->selectRaw('geohashEncode(lng, lat, '.$precision.') AS geohash')
            // uniqExact(id), not count(): hits_cold also holds duplicate rows per hit id
            // that FINAL cannot collapse, because the sort key leads with the mutable
            // hotlist_org_id/hit_created_at. Those would promote cells into the wrong
            // hit_tier_* bucket. FINAL and uniqExact fix different halves of this.
            ->selectRaw('uniqExact(id) AS hit_count')
            ->groupByRaw('geohash');

        $tierConditions = array_filter(array_map(fn ($tier) => match ($tier) {
            'hit_tier_1' => 'hit_count = 1',
            'hit_tier_2_5' => '(hit_count >= 2 AND hit_count <= 5)',
            'hit_tier_6_15' => '(hit_count >= 6 AND hit_count <= 15)',
            'hit_tier_16_50' => '(hit_count >= 16 AND hit_count <= 50)',
            'hit_tier_51_plus' => 'hit_count >= 51',
            default => null,
        }, $tiers));

        if (! empty($tierConditions)) {
            $query->havingRaw('('.implode(' OR ', $tierConditions).')');
        }

        return $query->get()->toArray();
    }

    private static function sanitizePrecision(mixed $precision): int
    {
        $precision = (int) $precision;

        return $precision >= 6 && $precision <= 9 ? $precision : 6;
    }

    public static function getTier(int $count): string
    {
        return match (true) {
            $count >= 51 => 'hit_tier_51_plus',
            $count >= 16 => 'hit_tier_16_50',
            $count >= 6 => 'hit_tier_6_15',
            $count >= 2 => 'hit_tier_2_5',
            default => 'hit_tier_1',
        };
    }

    public static function getTierColor(int $count): string
    {
        return match (true) {
            $count >= 51 => '#D84315',
            $count >= 16 => '#F4511E',
            $count >= 6 => '#FF7043',
            $count >= 2 => '#FFAB91',
            default => '#FFD6CC',
        };
    }

    public static function getTierLabel(int $count): string
    {
        return match (self::getTier($count)) {
            'hit_tier_1' => '1 hit',
            'hit_tier_2_5' => '2-5 hits',
            'hit_tier_6_15' => '6-15 hits',
            'hit_tier_16_50' => '16-50 hits',
            'hit_tier_51_plus' => '51+ hits',
            default => '',
        };
    }
}
